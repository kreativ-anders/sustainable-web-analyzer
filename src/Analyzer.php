<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Runs a sustainable web analysis for one URL.
 *
 * Phase 1 (parallel): page HTML + green hosting check.
 * Phase 2 (parallel): HEAD requests for all sub-resources + country lookups of their hosts.
 * Phase 3 (parallel): full downloads of the sub-resources whose HEAD response had no usable Content-Length.
 *
 * Every gate that is reached (see Limit) is reported in meta.limits and rates the page F.
 */
final class Analyzer
{
    private const HTML_ACCEPT = 'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8';

    public function __construct(
        private readonly UrlGuard $guard,
        private readonly HttpClient $http,
        private readonly GreenCheck $greenCheck,
        private readonly GeoLocator $geoLocator,
        private readonly int $maxExecutionTime,
        private readonly int $maxResources,
        private readonly int $maxHtmlBytes,
        private readonly string $timezone,
        private readonly bool $headRequests = true,
        private readonly int $maxResourceBytes = 50_000_000,
        private readonly ?int $maxDownloadBytes = null,
    ) {
    }

    public static function fromConfig(Config $config, Cache $cache): self
    {
        $guard = new UrlGuard();

        return new self(
            $guard,
            new HttpClient(
                $guard,
                concurrency: (int) $config->get('concurrency'),
                connectTimeoutMs: (int) $config->get('connect_timeout') * 1000,
                requestTimeoutMs: (int) $config->get('request_timeout') * 1000,
                maxRedirects: (int) $config->get('max_redirects'),
                maxResponseBytes: (int) $config->get('max_resource_bytes'),
                userAgent: (string) $config->get('user_agent'),
                verifyTls: (bool) $config->get('verify_tls'),
            ),
            new GreenCheck((string) $config->get('green_check_url')),
            new GeoLocator(
                $cache,
                (string) $config->get('ipinfo_url'),
                (string) $config->get('ipinfo_token'),
                (int) $config->get('geo_cache_ttl'),
                (int) $config->get('max_geo_lookups'),
                new RateLimiter((string) $config->get('cache_dir') . '/ratelimit', 86_400),
                (int) $config->get('geo_lookups_per_day'),
            ),
            maxExecutionTime: (int) $config->get('max_execution_time'),
            maxResources: (int) $config->get('max_resources'),
            maxHtmlBytes: (int) $config->get('max_html_bytes'),
            timezone: (string) $config->get('timezone'),
            headRequests: (bool) $config->get('head_requests'),
            maxResourceBytes: (int) $config->get('max_resource_bytes'),
            maxDownloadBytes: (int) $config->get('max_download_bytes') ?: null,
        );
    }

    /**
     * @param string $url Canonical URL from UrlGuard::normalizeInput().
     * @return array<string, mixed> Response data, see README.md.
     * @throws AnalyzerException
     */
    public function analyze(string $url): array
    {
        $start = microtime(true);
        $deadline = $start + $this->maxExecutionTime;
        $host = Url::host($url);

        // Fail early with a specific message for internal or unknown hosts.
        $this->guard->pin($url);

        $results = $this->http->fetchAll([
            'page' => new HttpRequest($url, maxBodyBytes: $this->maxHtmlBytes, headers: [self::HTML_ACCEPT]),
            'green' => $this->greenCheck->request($host),
        ], $deadline);

        $page = $results['page'];
        $limits = [];
        if (!$page->ok()) {
            throw AnalyzerException::unreachable((string) $page->error);
        }
        if ($page->status >= 400) {
            throw AnalyzerException::unreachable("HTTP {$page->status}");
        }
        $green = $this->greenCheck->parse($results['green']);
        self::reached($limits, $page->limit);

        $html = HtmlInspector::inspect($page->body, $page->url);
        $candidates = array_values(array_filter(
            $html['resources'],
            static fn (string $resource): bool => $resource !== $url && $resource !== $page->url,
        ));
        $resources = array_slice($candidates, 0, $this->maxResources);
        if (count($candidates) > count($resources)) {
            self::reached($limits, Limit::Resources);
        }

        // Resolve every host once; the guard memoizes the IPs for the pinned requests below.
        $ips = [];
        foreach (array_unique(array_merge([$host], array_map(Url::host(...), $resources))) as $resourceHost) {
            try {
                $ips[$resourceHost] = $this->guard->resolve($resourceHost);
            } catch (AnalyzerException) {
                // Requests to this host fail in the HTTP client and are skipped.
            }
        }

        // Sizes are taken from the Content-Length of HEAD responses; downloads are only the fallback.
        $batch = $this->geoLocator->requests(array_values($ips));
        foreach ($resources as $index => $resource) {
            $batch['resource:' . $index] = $this->headRequests ? HttpRequest::head($resource) : new HttpRequest($resource);
        }
        $results = $this->http->fetchAll($batch, $deadline, $this->headRequests ? null : $this->maxDownloadBytes);

        $sizes = [];
        $downloads = [];
        foreach ($resources as $index => $resource) {
            $result = $results['resource:' . $index];

            if (!$this->headRequests) {
                $sizes[$index] = $this->size($result, $limits);
            } elseif ($result->ok() && $result->status >= 200 && $result->status < 300 && $result->contentLength > 0) {
                $sizes[$index] = $this->cap($result->contentLength, $limits);
            } else {
                // A HEAD request that ran out of time or redirects would fail the same way as a download.
                if ($result->limit === Limit::Time || $result->limit === Limit::Redirects) {
                    self::reached($limits, $result->limit);
                    $sizes[$index] = null;
                    continue;
                }
                // No Content-Length (e.g. chunked), HEAD not supported, or an error: measure the real download.
                $downloads['resource:' . $index] = new HttpRequest($resource);
            }
        }

        foreach ($this->http->fetchAll($downloads, $deadline, $this->maxDownloadBytes) as $id => $result) {
            $sizes[(int) substr($id, strlen('resource:'))] = $this->size($result, $limits);
        }

        $downloaded = $this->headRequests ? count($downloads) : count($resources);

        $requests = [$url => $this->cap($page->bytes, $limits)];
        $extensions = [];
        $skipped = 0;
        foreach ($resources as $index => $resource) {
            if ($sizes[$index] === null) {
                $skipped++;
                continue;
            }
            $requests[$resource] = $sizes[$index];
            $extensions[] = Url::extension($resource);
        }

        $countries = $this->geoLocator->countries(array_values($ips), $results);
        $hosts = [];
        foreach (array_keys($requests) as $requestUrl) {
            $requestHost = Url::host($requestUrl);
            $hosts[$requestHost] ??= isset($ips[$requestHost]) ? $countries[$ips[$requestHost]] : null;
        }

        $bytes = array_sum($requests);

        $bytesByCountry = [];
        foreach ($requests as $requestUrl => $requestBytes) {
            $country = $hosts[Url::host($requestUrl)] ?? '';
            $bytesByCountry[$country] = ($bytesByCountry[$country] ?? 0) + $requestBytes;
        }

        $elapsed = microtime(true) - $start;
        if (microtime(true) >= $deadline) {
            self::reached($limits, Limit::Time);
        }
        $limits = array_values(array_filter(Limit::cases(), static fn (Limit $limit): bool => isset($limits[$limit->value])));

        return [
            'meta' => [
                'url' => $url,
                'analyzed_at' => (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format(DATE_ATOM),
                'final_url' => $page->url,
                'duration_ms' => (int) round($elapsed * 1000),
                'skipped' => $skipped,
                'sizes' => ['content_length' => count($resources) - $downloaded, 'download' => $downloaded],
                'truncated' => $limits !== [],
                'limits' => array_map(static fn (Limit $limit): string => $limit->value, $limits),
                'cached' => false,
                'version' => Config::VERSION,
            ],
            'requests' => (object) $requests,
            'bytes' => $bytes,
            'types' => (object) array_count_values($extensions),
            'hosts' => (object) $hosts,
            'green' => $green,
            'cookies' => $page->setsCookie || $html['consentManager'],
            'divification' => $html['divification'],
            'co2' => Co2::report($bytes, $green, GridIntensity::dataCenter($bytesByCountry), penalized: $limits !== []),
        ];
    }

    /**
     * Measured bytes of a sub-resource download, or null if it could not be measured.
     *
     * @param array<string, Limit> $limits
     */
    private function size(HttpResult $result, array &$limits): ?int
    {
        self::reached($limits, $result->limit);

        return $result->ok() ? $this->cap($result->bytes, $limits) : null;
    }

    /**
     * A transfer never counts with more than max_resource_bytes (also protects the byte sum from a forged Content-Length).
     *
     * @param array<string, Limit> $limits
     */
    private function cap(int $bytes, array &$limits): int
    {
        if ($bytes > $this->maxResourceBytes) {
            self::reached($limits, Limit::ResourceBytes);

            return $this->maxResourceBytes;
        }

        return $bytes;
    }

    /**
     * @param array<string, Limit> $limits
     */
    private static function reached(array &$limits, ?Limit $limit): void
    {
        if ($limit !== null) {
            $limits[$limit->value] = $limit;
        }
    }
}
