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
    private readonly Closure $analyzerFactory;

    /**
     * @param (Closure(Config, Cache): Analyzer)|null $analyzerFactory Replaceable for tests.
     */
    public function __construct(private readonly Config $config, ?Closure $analyzerFactory = null)
    {
        $this->cache = new Cache((string) $config->get('cache_dir'));
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
        if (!$this->isAllowedCaller($server, $originAllowed)) {
            return $this->error(403, 'Zugriff verweigert.', $headers);
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

            $retryAfter = $this->rateLimit($server);
            if ($retryAfter > 0) {
                return $this->error(429, 'Zu viele Anfragen. Bitte versuche es später erneut.', $headers + ['Retry-After' => (string) $retryAfter]);
            }

            // Concurrent requests for the same URL wait for the first analysis instead of repeating it.
            $lock = $this->cache->lock($cacheKey);
            try {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $this->success($cached, true, $headers);
                }

                $analyzer = ($this->analyzerFactory)($this->config, $this->cache);
                $json = json_encode($analyzer->analyze($url), self::JSON_FLAGS);
                $this->cache->set($cacheKey, $json, $this->cacheTtl(json_decode($json, false, 512, JSON_THROW_ON_ERROR)));

                return $this->success($json, false, $headers);
            } finally {
                $this->cache->unlock($lock);
            }
        } catch (AnalyzerException $e) {
            return $this->error($e->status, $e->getMessage(), $headers, $e->detail);
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
     * Browsers send an Origin header on cross-origin fetches; same-origin GETs are recognised
     * by Sec-Fetch-Site. This keeps casual direct use away – it is not a security boundary,
     * the rate limiter is.
     */
    private function isAllowedCaller(array $server, bool $originAllowed): bool
    {
        if ($originAllowed || !$this->config->get('require_origin')) {
            return true;
        }

        return !isset($server['HTTP_ORIGIN']) && ($server['HTTP_SEC_FETCH_SITE'] ?? null) === 'same-origin';
    }

    private function rateLimit(array $server): int
    {
        $limiter = new RateLimiter($this->config->get('cache_dir') . '/ratelimit', (int) $this->config->get('rate_limit_window'));

        $retryAfter = $limiter->hit('ip:' . $this->clientKey($server), (int) $this->config->get('rate_limit_per_ip'));

        return $retryAfter > 0 ? $retryAfter : $limiter->hit('global', (int) $this->config->get('rate_limit_global'));
    }

    /**
     * Client IP (IPv6 grouped by /64, since a single client usually controls a whole /64).
     */
    private function clientKey(array $server): string
    {
        $ip = $server['REMOTE_ADDR'] ?? '';
        $header = $this->config->get('client_ip_header');

        if (is_string($header) && is_string($server[$header] ?? null)) {
            $forwarded = trim((string) strrchr(',' . $server[$header], ','), " ,");
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                $ip = $forwarded;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return bin2hex(substr((string) inet_pton($ip), 0, 8)) . '::/64';
        }

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
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
