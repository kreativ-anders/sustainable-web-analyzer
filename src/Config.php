<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuration: built-in defaults < config.php < environment variables.
 */
final class Config
{
    public const VERSION = '1.0.0';

    private const DEFAULTS = [
        // General
        'enabled' => true,             // false = maintenance mode (HTTP 503)
        'debug' => false,              // true = append internal error details to error messages
        'timezone' => 'Europe/Berlin',

        // Access control
        'allowed_origins' => ['https://kreativ-anders.de', 'https://www.kreativ-anders.de'],
        'require_origin' => true,      // reject requests that are neither from an allowed origin nor same-origin
        'client_ip_header' => null,    // e.g. 'HTTP_X_REAL_IP' – only when running behind a trusted reverse proxy
        'rate_limit_window' => 600,    // seconds
        'rate_limit_per_ip' => 10,     // uncached analyses per IP per window (0 = unlimited)
        'rate_limit_global' => 300,    // uncached analyses in total per window (0 = unlimited)

        // Caching
        'cache_dir' => null,           // null = <project>/var/cache
        'cache_ttl' => 604800,         // analysis results: 1 week
        'geo_cache_ttl' => 1209600,    // IP -> country lookups: 2 weeks

        // Crawling limits
        'max_execution_time' => 20,    // seconds for a complete analysis
        'connect_timeout' => 3,        // seconds per connection
        'request_timeout' => 8,        // seconds per request
        'concurrency' => 10,           // parallel requests
        'max_resources' => 150,        // sub-resources fetched per page
        'max_redirects' => 5,
        'max_html_bytes' => 5_000_000,        // HTML kept in memory for parsing
        'max_resource_bytes' => 50_000_000,   // transfers are aborted beyond this size
        'user_agent' => 'SustainableWebAnalyzer/1.0 (+https://kreativ-anders.de/web-analyse)',
        'verify_tls' => true,
        'head_requests' => true,       // read sizes from HEAD Content-Length, download only as fallback

        // External services
        'green_check_url' => 'https://api.thegreenwebfoundation.org/api/v3/greencheck/',
        'ipinfo_url' => 'https://ipinfo.io/',
        'ipinfo_token' => '',
    ];

    private const ENV = [
        'SWA_ENABLED' => 'enabled',
        'SWA_DEBUG' => 'debug',
        'SWA_ALLOWED_ORIGINS' => 'allowed_origins',
        'SWA_CACHE_DIR' => 'cache_dir',
        'SWA_IPINFO_TOKEN' => 'ipinfo_token',
    ];

    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, mixed> $overrides Applied last (useful for tests and the CLI).
     */
    public static function load(string $root, array $overrides = []): self
    {
        $values = self::DEFAULTS;

        $file = $root . '/config.php';
        if (is_file($file)) {
            $local = require $file;
            if (!is_array($local)) {
                throw new RuntimeException('config.php must return an array.');
            }
            $values = array_replace($values, $local);
        }

        foreach (self::ENV as $variable => $key) {
            $value = getenv($variable);
            if ($value === false) {
                continue;
            }
            $values[$key] = match ($key) {
                'enabled', 'debug' => filter_var($value, FILTER_VALIDATE_BOOL),
                'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', $value)))),
                default => $value,
            };
        }

        $values = array_replace($values, $overrides);

        foreach (array_keys($values) as $key) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                throw new InvalidArgumentException("Unknown configuration key \"{$key}\".");
            }
        }

        $values['cache_dir'] ??= $root . '/var/cache';

        return new self($values);
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new InvalidArgumentException("Unknown configuration key \"{$key}\".");
        }

        return $this->values[$key];
    }
}
