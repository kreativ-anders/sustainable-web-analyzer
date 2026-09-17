<?php

declare(strict_types=1);

/**
 * Dependency-free test runner.
 *
 *   php tests/run.php            unit tests (no network)
 *   php tests/run.php --network  additionally analyzes a live website
 */

use SustainableWebAnalyzer\Analyzer;
use SustainableWebAnalyzer\AnalyzerException;
use SustainableWebAnalyzer\Api;
use SustainableWebAnalyzer\Cache;
use SustainableWebAnalyzer\Co2;
use SustainableWebAnalyzer\Config;
use SustainableWebAnalyzer\HtmlInspector;
use SustainableWebAnalyzer\RateLimiter;
use SustainableWebAnalyzer\Url;
use SustainableWebAnalyzer\UrlGuard;

require dirname(__DIR__) . '/src/bootstrap.php';

$failures = 0;
$assertions = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failures, $assertions;
    $assertions++;

    if ($expected !== $actual) {
        $failures++;
        echo "✗ {$name}\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
    }
}

function throws(string $name, callable $callback, int $status): void
{
    try {
        $callback();
        check($name, "AnalyzerException({$status})", 'no exception');
    } catch (AnalyzerException $e) {
        check($name, $status, $e->status);
    }
}

function temporaryDirectory(): string
{
    $directory = sys_get_temp_dir() . '/swa-test-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);

    return $directory;
}

// ---------------------------------------------------------------------------------------------
// Url
// ---------------------------------------------------------------------------------------------

$base = 'https://example.com/blog/post/index.html?x=1';
foreach ([
    'style.css' => 'https://example.com/blog/post/style.css',
    './style.css' => 'https://example.com/blog/post/style.css',
    '../img/a.png' => 'https://example.com/blog/img/a.png',
    '../../../../a.js' => 'https://example.com/a.js',
    '/assets/app.js?v=2#top' => 'https://example.com/assets/app.js?v=2',
    '//cdn.example.org/lib.js' => 'https://cdn.example.org/lib.js',
    'http://Other.example.org/a b.png' => 'https://other.example.org/a%20b.png',
    'HTTPS://example.com/ä.png' => 'https://example.com/%C3%A4.png',
    '?page=2' => 'https://example.com/blog/post/index.html?page=2',
    "  /with\nnewline.css " => 'https://example.com/withnewline.css',
    '#section' => null,
    '' => null,
    'data:image/png;base64,AAAA' => null,
    'javascript:alert(1)' => null,
    'ftp://example.com/file' => null,
    'mailto:me@example.com' => null,
] as $reference => $expected) {
    check("Url::resolve('{$reference}')", $expected, Url::resolve($base, (string) $reference));
}

check('Url::removeDotSegments /a/b/../c', '/a/c', Url::removeDotSegments('/a/b/../c'));
check('Url::removeDotSegments /a/..', '/', Url::removeDotSegments('/a/..'));
check('Url::removeDotSegments /a/./b/', '/a/b/', Url::removeDotSegments('/a/./b/'));
check('Url::extension', 'css', Url::extension('https://example.com/a/Style.CSS?v=1'));
check('Url::extension none', '', Url::extension('https://example.com/a/'));
check('Url::extension dotted directory', '', Url::extension('https://example.com/v1.2/app'));

// ---------------------------------------------------------------------------------------------
// UrlGuard
// ---------------------------------------------------------------------------------------------

check('normalize: adds scheme and path', 'https://kreativ-anders.de/', UrlGuard::normalizeInput('kreativ-anders.de'));
check('normalize: upgrades http', 'https://example.com/a?b=1', UrlGuard::normalizeInput(' http://EXAMPLE.com/a?b=1 '));
check('normalize: explicit port 443', 'https://example.com/', UrlGuard::normalizeInput('https://example.com:443'));

throws('normalize: empty', fn () => UrlGuard::normalizeInput(''), 400);
throws('normalize: whitespace inside', fn () => UrlGuard::normalizeInput('https://exa mple.com'), 400);
throws('normalize: ftp', fn () => UrlGuard::normalizeInput('ftp://example.com'), 400);
throws('normalize: credentials', fn () => UrlGuard::normalizeInput('https://user:pass@example.com'), 400);
throws('normalize: other port', fn () => UrlGuard::normalizeInput('https://example.com:8080'), 422);
throws('normalize: localhost', fn () => UrlGuard::normalizeInput('https://localhost/'), 400);
throws('normalize: single label host', fn () => UrlGuard::normalizeInput('https://intranet/'), 400);
throws('normalize: .internal', fn () => UrlGuard::normalizeInput('https://db.internal/'), 400);
throws('normalize: IPv6 literal', fn () => UrlGuard::normalizeInput('https://[::1]/'), 400);
throws('normalize: too long', fn () => UrlGuard::normalizeInput('https://example.com/' . str_repeat('a', 2100)), 400);

foreach ([
    '127.0.0.1' => false, '10.1.2.3' => false, '172.16.0.1' => false, '192.168.1.1' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '0.0.0.0' => false, '255.255.255.255' => false,
    '::1' => false, 'fd00::1' => false, 'fe80::1' => false, '::ffff:127.0.0.1' => false, '::ffff:7f00:1' => false,
    '64:ff9b::a00:1' => false, '2002:a00:1::' => false,
    '8.8.8.8' => true, '185.199.108.153' => true, '2a00:1450:4001:80b::200e' => true, '::ffff:8.8.8.8' => true,
] as $ip => $expected) {
    check("isPublicIp({$ip})", $expected, UrlGuard::isPublicIp((string) $ip));
}

$guard = new UrlGuard();
throws('pin: loopback IP', fn () => $guard->pin('https://127.0.0.1/'), 422);
throws('pin: metadata IP', fn () => $guard->pin('https://169.254.169.254/latest/meta-data/'), 422);
throws('pin: http', fn () => $guard->pin('http://8.8.8.8/'), 422);
throws('pin: port', fn () => $guard->pin('https://8.8.8.8:8443/'), 422);
check('pin: public IP literal', '8.8.8.8:443:8.8.8.8', $guard->pin('https://8.8.8.8/'));

// ---------------------------------------------------------------------------------------------
// HtmlInspector
// ---------------------------------------------------------------------------------------------

$html = <<<'HTML'
<!doctype html>
<html><head>
  <link rel="preconnect" href="https://fonts.example.org">
  <link rel="dns-prefetch" href="https://app.usercentrics.eu">
  <link rel="shortcut icon" href="/favicon.ico">
  <link rel="canonical" href="https://example.com/">
  <link rel="stylesheet" href="css/app.css">
  <link rel="preload" href="/fonts/a.woff2" as="font">
  <script src="//cdn.example.org/lib.js"></script>
  <script>inline()</script>
</head><body>
  <img src="data:image/gif;base64,R0lGOD" alt="icon">
  <img src="img/äpfel.webp" alt="author">
  <img src="css/app.css">
  <div><div><div><div>one</div></div></div></div>
</body></html>
HTML;

$report = HtmlInspector::inspect($html, 'https://example.com/shop/');
check('inspect: resources', [
    'https://cdn.example.org/lib.js',
    'https://example.com/shop/css/app.css',
    'https://example.com/fonts/a.woff2',
    'https://example.com/shop/img/%C3%A4pfel.webp',
], $report['resources']);
check('inspect: consent manager from dns-prefetch', true, $report['consentManager']);
check('inspect: single deep div is no div-ification', false, $report['divification']);

$report = HtmlInspector::inspect('<base href="https://static.example.net/v2/"><div><div><div><div><div>x</div></div></div></div></div><script src="app.js"></script>', 'https://example.com/');
check('inspect: base href', ['https://static.example.net/v2/app.js'], $report['resources']);
check('inspect: div-ification', true, $report['divification']);
check('inspect: no consent manager', false, $report['consentManager']);
check('inspect: empty document', ['resources' => [], 'divification' => false, 'consentManager' => false], HtmlInspector::inspect('', 'https://example.com/'));

// ---------------------------------------------------------------------------------------------
// Co2 – reference values produced by @tgwf/co2@0.13.2: new co2({model: "swd"}).perVisit()/perByte()
// ---------------------------------------------------------------------------------------------

$reference = json_decode('[[0,false,0,0],[0,true,0,0],[1,false,2.703051e-7,3.5802e-7],[1,true,2.3434596e-7,3.10392e-7],[1000,false,0.00027030509999999996,0.00035802],[1000,true,0.00023434595999999997,0.00031039200000000005],[123456,false,0.033370786425600006,0.04419971712000001],[123456,true,0.02893141483776001,0.03831975475200001],[1000000,false,0.2703051000000001,0.35802000000000006],[1000000,true,0.23434596000000005,0.31039200000000006],[2200000,false,0.5946712200000001,0.7876440000000001],[2200000,true,0.5155611120000001,0.6828624000000001],[7654321,false,2.0690020033370997,2.74040000442],[7654321,true,1.7937592028931597,2.3758400038320002],[1000000000,false,270.3051,358.02],[1000000000,true,234.34596000000002,310.392]]', true);

foreach ($reference as [$bytes, $green, $perVisit, $perByte]) {
    $label = $bytes . ($green ? ' green' : '');
    check("Co2::perVisit({$label})", (float) $perVisit, Co2::perVisit($bytes, $green));
    check("Co2::perByte({$label})", (float) $perByte, Co2::perByte($bytes, $green));
}

// ---------------------------------------------------------------------------------------------
// Cache & RateLimiter
// ---------------------------------------------------------------------------------------------

$cacheDirectory = temporaryDirectory();
$cache = new Cache($cacheDirectory);
check('cache: miss', null, $cache->get('a'));
$cache->set('a', "multi\nline", 60);
check('cache: hit', "multi\nline", $cache->get('a'));
$cache->set('b', 'old', -1);
check('cache: expired', null, $cache->get('b'));
$lock = $cache->lock('a');
$cache->unlock($lock);
$cache->prune();
check('cache: prune keeps valid entries', "multi\nline", $cache->get('a'));

$limiter = new RateLimiter($cacheDirectory . '/ratelimit', 60);
check('rate limit: 1st', 0, $limiter->hit('ip:1.2.3.4', 2));
check('rate limit: 2nd', 0, $limiter->hit('ip:1.2.3.4', 2));
check('rate limit: 3rd blocked', true, $limiter->hit('ip:1.2.3.4', 2) > 0);
check('rate limit: other bucket', 0, $limiter->hit('ip:5.6.7.8', 2));
check('rate limit: disabled', 0, $limiter->hit('ip:1.2.3.4', 0));

// ---------------------------------------------------------------------------------------------
// Api
// ---------------------------------------------------------------------------------------------

$root = dirname(__DIR__);
$makeApi = static fn (array $overrides = []): Api => new Api(Config::load($root, $overrides + [
    'cache_dir' => temporaryDirectory(),
    'allowed_origins' => ['https://kreativ-anders.de'],
    'require_origin' => true,
]));
$browser = ['REQUEST_METHOD' => 'GET', 'HTTP_ORIGIN' => 'https://kreativ-anders.de', 'REMOTE_ADDR' => '203.0.113.7'];
$body = static fn ($response): array => json_decode($response->body, true);

$api = $makeApi();

$response = $api->handle(['REQUEST_METHOD' => 'OPTIONS'] + $browser, []);
check('api: preflight status', 204, $response->status);
check('api: preflight CORS origin', 'https://kreativ-anders.de', $response->headers['Access-Control-Allow-Origin'] ?? null);

$response = $api->handle(['HTTP_ORIGIN' => 'https://evil.example'] + $browser, ['url' => 'example.com']);
check('api: foreign origin', 403, $response->status);
check('api: foreign origin gets no CORS header', false, isset($response->headers['Access-Control-Allow-Origin']));

$response = $api->handle(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.7'], ['url' => 'example.com']);
check('api: no origin', 403, $response->status);

$response = $api->handle(['REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'REMOTE_ADDR' => '203.0.113.7'], ['url' => 'https://127.0.0.1']);
check('api: same-origin request is allowed', 422, $response->status);

check('api: POST', 405, $api->handle(['REQUEST_METHOD' => 'POST'] + $browser, [])->status);
check('api: missing url', ['error' => 'Bitte gebe eine URL ein.'], $body($api->handle($browser, [])));
check('api: url as array', 400, $api->handle($browser, ['url' => ['x']])->status);
check('api: invalid url', ['error' => 'Der eingegebene Wert ist keine gültige URL.'], $body($api->handle($browser, ['url' => 'not a url'])));

$response = $api->handle($browser, ['url' => 'https://169.254.169.254/latest/meta-data/']);
check('api: SSRF blocked', 422, $response->status);
check('api: SSRF message', ['error' => 'Die zu analysierende URL stellt ein Sicherheitsrisiko dar.'], $body($response));
check('api: errors are not cached', 'no-store', $response->headers['Cache-Control']);
check('api: nosniff', 'nosniff', $response->headers['X-Content-Type-Options']);

check('api: maintenance', 503, $makeApi(['enabled' => false])->handle($browser, ['url' => 'example.com'])->status);

$debugResponse = $makeApi(['debug' => true])->handle($browser, ['url' => 'https://127.0.0.1/']);
check('api: debug detail', true, str_contains($body($debugResponse)['error'], 'non-public address'));

$limited = $makeApi(['rate_limit_per_ip' => 1]);
check('api: rate limit 1st', 422, $limited->handle($browser, ['url' => 'https://127.0.0.1/'])->status);
$response = $limited->handle($browser, ['url' => 'https://10.0.0.1/']);
check('api: rate limit 2nd', 429, $response->status);
check('api: Retry-After', true, (int) ($response->headers['Retry-After'] ?? 0) > 0);

check('cacheKey: trailing slash', Api::cacheKey('https://example.com/a'), Api::cacheKey('https://example.com/a/'));
check('cacheKey: root', 'result:example.com/', Api::cacheKey('https://example.com/'));
check('cacheKey: query matters', false, Api::cacheKey('https://example.com/?p=1') === Api::cacheKey('https://example.com/?p=2'));

$cacheDirectory = temporaryDirectory();
(new Cache($cacheDirectory))->set(Api::cacheKey('https://example.com/'), '{"meta":{"url":"https://example.com/","cached":false},"requests":{},"types":{},"hosts":{}}', 60);
$response = (new Api(Config::load($root, ['cache_dir' => $cacheDirectory, 'allowed_origins' => ['https://kreativ-anders.de']])))
    ->handle($browser, ['url' => 'example.com']);
check('api: cache hit status', 200, $response->status);
check('api: cache hit flag', true, $body($response)['meta']['cached']);
check('api: cache hit keeps empty objects', true, str_contains($response->body, '"requests":{}'));
check('api: cache hit is publicly cacheable', true, str_starts_with($response->headers['Cache-Control'], 'public'));

// ---------------------------------------------------------------------------------------------
// Live analysis (optional)
// ---------------------------------------------------------------------------------------------

if (in_array('--network', $argv, true)) {
    $target = $argv[array_search('--network', $argv, true) + 1] ?? 'https://kreativ-anders.de/';
    $config = Config::load($root, ['cache_dir' => temporaryDirectory()]);
    $result = Analyzer::fromConfig($config, new Cache((string) $config->get('cache_dir')))->analyze(UrlGuard::normalizeInput($target));
    $json = json_decode(json_encode($result), true);

    echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    check('live: at least one request', true, count($json['requests']) >= 1);
    check('live: bytes are the sum of requests', array_sum($json['requests']), $json['bytes']);
    check('live: every request host is listed', [], array_values(array_diff(array_unique(array_map(Url::host(...), array_keys($json['requests']))), array_keys($json['hosts']))));
    check('live: co2 matches bytes', Co2::perVisit($json['bytes'], $json['green']), $json['co2']['per_visit']);
}

echo $failures === 0 ? "✓ {$assertions} assertions passed\n" : "\n{$failures} of {$assertions} assertions failed\n";
exit($failures === 0 ? 0 : 1);
