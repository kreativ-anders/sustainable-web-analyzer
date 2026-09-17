<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuration: config.default.php < config.php < environment variables.
 */
final class Config
{
    public const VERSION = '1.0.0';

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
        $defaults = require $root . '/config.default.php';
        $values = $defaults;

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
            if (!array_key_exists($key, $defaults)) {
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
