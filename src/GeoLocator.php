<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * IP -> ISO country code via ipinfo.io, cached per IP.
 *
 * Budgeted against the monthly ipinfo quota. A lookup that is not made leaves the country null,
 * which the report handles – the page is still measured.
 */
final class GeoLocator
{
    private const PREFIX = 'geo:';

    public function __construct(
        private readonly Cache $cache,
        private readonly string $endpoint,
        private readonly string $token,
        private readonly int $ttl,
        private readonly int $maxLookups = 0,
        private readonly ?RateLimiter $limiter = null,
        private readonly int $dailyBudget = 0,
    ) {
    }

    /**
     * Requests for all IPs that are not cached yet.
     *
     * @param list<string> $ips
     * @return array<string, HttpRequest>
     */
    public function requests(array $ips): array
    {
        $headers = ['Accept: text/plain'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $pending = [];
        foreach (array_unique($ips) as $ip) {
            if ($this->cache->get(self::PREFIX . $ip) === null) {
                $pending[] = $ip;
            }
        }

        if ($this->maxLookups > 0) {
            $pending = array_slice($pending, 0, $this->maxLookups);
        }

        $granted = $this->limiter?->take('geo:day', $this->dailyBudget, count($pending)) ?? count($pending);

        $requests = [];
        foreach (array_slice($pending, 0, $granted) as $ip) {
            $requests[self::PREFIX . $ip] = new HttpRequest(
                rtrim($this->endpoint, '/') . '/' . rawurlencode($ip) . '/country',
                guarded: false,
                maxBodyBytes: 256,
                headers: $headers,
            );
        }

        return $requests;
    }

    /**
     * Country code per IP from the cache or the results of requests(); null if unknown.
     *
     * @param list<string> $ips
     * @param array<string, HttpResult> $results
     * @return array<string, ?string>
     */
    public function countries(array $ips, array $results): array
    {
        $countries = [];

        foreach (array_unique($ips) as $ip) {
            $key = self::PREFIX . $ip;
            $country = $this->cache->get($key);

            if ($country === null && isset($results[$key]) && $results[$key]->ok() && $results[$key]->status === 200) {
                $candidate = strtoupper(trim($results[$key]->body));
                if (preg_match('/^[A-Z]{2}$/', $candidate)) {
                    $country = $candidate;
                    $this->cache->set($key, $country, $this->ttl);
                }
            }

            $countries[$ip] = $country;
        }

        return $countries;
    }
}
