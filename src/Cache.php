<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * File cache with atomic writes. Entries: "<expiry timestamp>\n<value>".
 */
final class Cache
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): ?string
    {
        $file = $this->path($key);
        $content = @file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $newline = strpos($content, "\n");
        if ($newline === false || (int) substr($content, 0, $newline) < time()) {
            @unlink($file);

            return null;
        }

        return substr($content, $newline + 1);
    }

    public function set(string $key, string $value, int $ttl): void
    {
        $file = $this->path($key);
        $this->ensureDirectory(dirname($file));

        $temporary = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, (time() + $ttl) . "\n" . $value) === false || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Could not write cache file.');
        }
    }

    /**
     * Block until the exclusive lock for $key is held. Release it with unlock().
     *
     * @return resource
     */
    public function lock(string $key)
    {
        $file = $this->path($key) . '.lock';
        $this->ensureDirectory(dirname($file));

        $handle = fopen($file, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not acquire cache lock.');
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Delete expired entries and leftovers (locks, temp files, rate-limit counters) older than a day.
     */
    public function prune(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $now = time();
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (str_ends_with($path, '.cache')) {
                $handle = @fopen($path, 'r');
                $expires = $handle !== false ? (int) fgets($handle, 32) : 0;
                if ($handle !== false) {
                    fclose($handle);
                }
                if ($expires < $now) {
                    @unlink($path);
                }
            } elseif ($file->getMTime() < $now - 86_400) {
                @unlink($path);
            }
        }
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create cache directory.');
        }
    }
}
