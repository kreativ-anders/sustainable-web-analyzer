<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use Closure;
use Throwable;

/**
 * HTTP layer: access control, CORS, rate limiting, result caching and error mapping.
 */
final class Api
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const SECURITY_HEADERS = [
        'Content-Type' => 'application/json; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        'Vary' => 'Origin',
    ];

    private readonly Cache $cache;
    private readonly RateLimiter $limiter;
    private readonly Semaphore $semaphore;
    private readonly Closure $analyzerFactory;

    /**
     * @param (Closure(Config, Cache): Analyzer)|null $analyzerFactory Replaceable for tests.
     */
    public function __construct(private readonly Config $config, ?Closure $analyzerFactory = null)
    {
        $directory = (string) $config->get('cache_dir');

        $this->cache = new Cache($directory);
        $this->limiter = new RateLimiter($directory . '/ratelimit', (int) $config->get('rate_limit_window'));
        $this->semaphore = new Semaphore($directory . '/slots', (int) $config->get('max_concurrent_analyses'));
        $this->analyzerFactory = $analyzerFactory ?? Analyzer::fromConfig(...);
    }

    /**
     * @param array<string, mixed> $server $_SERVER
     * @param array<string, mixed> $query $_GET
     */
    public function handle(array $server, array $query): Response
    {
        $headers = self::SECURITY_HEADERS;
        $origin = is_string($server['HTTP_ORIGIN'] ?? null) ? $server['HTTP_ORIGIN'] : null;
        $originAllowed = $origin !== null && in_array($origin, (array) $this->config->get('allowed_origins'), true);

        if ($originAllowed) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Methods'] = 'GET, OPTIONS';
            $headers['Access-Control-Max-Age'] = '86400';
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'OPTIONS') {
            return new Response($originAllowed ? 204 : 403, $headers + ['Cache-Control' => 'no-store'], '');
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->error(405, 'Methode nicht erlaubt.', $headers + ['Allow' => 'GET, HEAD, OPTIONS']);
        }
        if (!$this->config->get('enabled')) {
            return $this->error(503, 'Der CO2 Check befindet sich im Wartungsmodus.', $headers);
        }

        $ip = $this->clientIp($server);
        $exempt = $this->isExempt($ip);

        if (!$exempt) {
            $headers += $this->budgetHeaders($ip);

            // Counts cached answers too: serving one is cheap, but not free.
            $retryAfter = $this->limiter->hit('req:' . self::clientKey($ip), (int) $this->config->get('rate_limit_requests_per_ip'));
            if ($retryAfter > 0) {
                return $this->error(429, 'Zu viele Anfragen. Bitte versuche es ' . self::inWords($retryAfter) . ' erneut.', $headers + ['Retry-After' => (string) $retryAfter]);
            }
        }

        $input = $query['url'] ?? null;
        if (!is_string($input) || trim($input) === '') {
            return $this->error(400, 'Bitte gebe eine URL ein.', $headers);
        }

        try {
            $url = UrlGuard::normalizeInput($input);
            $cacheKey = self::cacheKey($url);

            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $this->success($cached, true, $headers);
            }

            if (!$exempt) {
                [$retryAfter, $message] = $this->rateLimit($ip, $url);
                if ($retryAfter > 0) {
                    return $this->error(429, $message, $this->budgetHeaders($ip) + $headers + ['Retry-After' => (string) $retryAfter]);
                }

                $headers = $this->budgetHeaders($ip) + $headers; // refresh: this request has just been counted
            }

            // Concurrent requests for the same URL wait for the first analysis instead of repeating it.
            $lock = $this->cache->lock($cacheKey);
            try {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $this->success($cached, true, $headers);
                }

                // After the lock: waiting for someone else's analysis must not occupy a slot.
                $json = $this->semaphore->run(function () use ($url, $cacheKey): string {
                    $analyzer = ($this->analyzerFactory)($this->config, $this->cache);
                    $json = json_encode($analyzer->analyze($url), self::JSON_FLAGS);
                    $this->cache->set($cacheKey, $json, $this->cacheTtl(json_decode($json, false, 512, JSON_THROW_ON_ERROR)));

                    return $json;
                });

                return $this->success($json, false, $headers);
            } finally {
                $this->cache->unlock($lock);
            }
        } catch (AnalyzerException $e) {
            $extra = $e->status === 503 ? ['Retry-After' => '30'] : [];

            return $this->error($e->status, $e->getMessage(), $headers + $extra, $e->detail);
        } catch (Throwable $e) {
            error_log('[sustainable-web-analyzer] ' . $e);

            return $this->error(500, 'Bei der Analyse ist ein unerwarteter Fehler aufgetreten.', $headers, $e->getMessage());
        }
    }

    /**
     * Housekeeping, meant to run after the response has been sent.
     */
    public function maintenance(): void
    {
        if (random_int(1, 200) === 1) {
            $this->cache->prune();
        }
    }

    /**
     * host + path with exactly one trailing slash + query – the scheme is always https.
     */
    public static function cacheKey(string $url): string
    {
        $parts = parse_url($url);
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return 'result:' . ($parts['host'] ?? '') . rtrim($parts['path'] ?? '', '/') . '/' . $query;
    }

    /**
     * A full bucket stops the ladder; the ones before it have already counted.
     *
     * @return array{0: int, 1: string} retry-after seconds (0 = allowed) and the message to send
     */
    private function rateLimit(string $ip, string $url): array
    {
        $perIp = (int) $this->config->get('rate_limit_per_ip');
        $host = (string) parse_url($url, PHP_URL_HOST);

        $retryAfter = $this->limiter->hit('ip:' . self::clientKey($ip), $perIp);
        if ($retryAfter > 0) {
            return [$retryAfter, "Du hast das Limit von {$perIp} Analysen pro Tag erreicht. Bitte versuche es " . self::inWords($retryAfter) . ' erneut.'];
        }

        $shared = [
            ['target:' . preg_replace('/^www\./', '', $host), (int) $this->config->get('rate_limit_per_target')],
            ['global', (int) $this->config->get('rate_limit_global')],
        ];

        foreach ($shared as [$bucket, $limit]) {
            $retryAfter = $this->limiter->hit($bucket, $limit);
            if ($retryAfter > 0) {
                return [$retryAfter, 'Der CO2 Check ist im Moment stark ausgelastet. Bitte versuche es ' . self::inWords($retryAfter) . ' erneut.'];
            }
        }

        return [0, ''];
    }

    /**
     * @return array<string, string>
     */
    private function budgetHeaders(string $ip): array
    {
        $limit = (int) $this->config->get('rate_limit_per_ip');

        if ($limit <= 0) {
            return [];
        }

        [$remaining, $resets] = $this->limiter->state('ip:' . self::clientKey($ip), $limit);

        return [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $resets,
        ];
    }

    /**
     * German "in 7 Stunden" for a Retry-After value.
     */
    private static function inWords(int $seconds): string
    {
        return match (true) {
            $seconds >= 5400 => 'in ' . (int) round($seconds / 3600) . ' Stunden',
            $seconds >= 2700 => 'in einer Stunde',
            $seconds >= 120 => 'in ' . (int) round($seconds / 60) . ' Minuten',
            default => 'in einer Minute',
        };
    }

    private function isExempt(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach ((array) $this->config->get('rate_limit_exempt_ips') as $entry) {
            $entry = (string) $entry;
            $matches = str_contains($entry, '/')
                ? UrlGuard::inRange($ip, $entry)
                : filter_var($entry, FILTER_VALIDATE_IP) !== false && inet_pton($entry) === inet_pton($ip);

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * WARNING: set client_ip_header only behind a proxy that always overwrites it. Otherwise every
     * caller can pick their own rate-limit bucket.
     *
     * @param array<string, mixed> $server
     */
    private function clientIp(array $server): string
    {
        $ip = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';
        $header = $this->config->get('client_ip_header');

        if (is_string($header) && is_string($server[$header] ?? null)) {
            $forwarded = trim((string) strrchr(',' . $server[$header], ','), " ,");
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                $ip = $forwarded;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * IPv6 is grouped by /64: one client usually controls the whole block.
     */
    private static function clientKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return bin2hex(substr((string) inet_pton($ip), 0, 8)) . '::/64';
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    /**
     * @param array<string, string> $headers
     */
    private function success(string $json, bool $cached, array $headers): Response
    {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if ($cached) {
            $data->meta->cached = true;
            $json = json_encode($data, self::JSON_FLAGS);
        }

        $headers['Cache-Control'] = 'public, max-age=' . $this->cacheTtl($data);

        return new Response(200, $headers, $json);
    }

    /**
     * Results that reached a gate are rated F; they expire sooner so a fixed page can be re-checked.
     */
    private function cacheTtl(object $data): int
    {
        return (int) $this->config->get(($data->co2->penalized ?? false) ? 'penalized_cache_ttl' : 'cache_ttl');
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $message, array $headers, string $detail = ''): Response
    {
        if ($this->config->get('debug') && $detail !== '') {
            $message .= " ({$detail})";
        }

        $headers['Cache-Control'] = 'no-store';

        return new Response($status, $headers, json_encode(['error' => $message], self::JSON_FLAGS));
    }
}
