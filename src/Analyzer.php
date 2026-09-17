<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Runs a sustainable web analysis for one URL.
 *
 * Phase 1 (parallel): page HTML + green hosting check.
 * Phase 2 (parallel): all sub-resources + country lookups of their hosts.
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
            ),
            maxExecutionTime: (int) $config->get('max_execution_time'),
            maxResources: (int) $config->get('max_resources'),
            maxHtmlBytes: (int) $config->get('max_html_bytes'),
            timezone: (string) $config->get('timezone'),
        );
    }

    /**
     * @param string $url Canonical URL from UrlGuard::normalizeInput().
     * @return array<string, mixed> Response data (same shape as the former Kirby plugin, plus meta fields).
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
        if (!$page->ok()) {
            throw AnalyzerException::unreachable((string) $page->error);
        }
        if ($page->status >= 400) {
            throw AnalyzerException::unreachable("HTTP {$page->status}");
        }
        $green = $this->greenCheck->parse($results['green']);

        $html = HtmlInspector::inspect($page->body, $page->url);
        $candidates = array_values(array_filter(
            $html['resources'],
            static fn (string $resource): bool => $resource !== $url && $resource !== $page->url,
        ));
        $resources = array_slice($candidates, 0, $this->maxResources);

        // Resolve every host once; the guard memoizes the IPs for the pinned requests below.
        $ips = [];
        foreach (array_unique(array_merge([$host], array_map(Url::host(...), $resources))) as $resourceHost) {
            try {
                $ips[$resourceHost] = $this->guard->resolve($resourceHost);
            } catch (AnalyzerException) {
                // Requests to this host fail in the HTTP client and are skipped.
            }
        }

        $batch = $this->geoLocator->requests(array_values($ips));
        foreach ($resources as $index => $resource) {
            $batch['resource:' . $index] = new HttpRequest($resource);
        }
        $results = $this->http->fetchAll($batch, $deadline);

        $requests = [$url => $page->bytes];
        $extensions = [];
        $skipped = 0;
        foreach ($resources as $index => $resource) {
            $result = $results['resource:' . $index];
            if (!$result->ok()) {
                $skipped++;
                continue;
            }
            $requests[$resource] = $result->bytes;
            $extensions[] = Url::extension($resource);
        }

        $countries = $this->geoLocator->countries(array_values($ips), $results);
        $hosts = [];
        foreach (array_keys($requests) as $requestUrl) {
            $requestHost = Url::host($requestUrl);
            $hosts[$requestHost] ??= isset($ips[$requestHost]) ? $countries[$ips[$requestHost]] : null;
        }

        $bytes = array_sum($requests);
        $elapsed = microtime(true) - $start;

        return [
            'meta' => [
                'url' => $url,
                'time' => (int) $elapsed,
                'last' => (new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->format('d.m.Y'),
                'final_url' => $page->url,
                'duration_ms' => (int) round($elapsed * 1000),
                'skipped' => $skipped,
                'truncated' => count($candidates) > count($resources) || microtime(true) >= $deadline,
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
            'co2' => Co2::report($bytes, $green),
        ];
    }
}
