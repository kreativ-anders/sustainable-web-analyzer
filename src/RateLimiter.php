<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * Fixed-window rate limiter backed by small lock-protected files.
 */
final class RateLimiter
{
    public function __construct(private readonly string $directory, private readonly int $window)
    {
    }

    /**
     * Count a hit for $bucket.
     *
     * @return int 0 if allowed, otherwise the seconds until the window resets.
     */
    public function hit(string $bucket, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('[sustainable-web-analyzer] Rate limiter directory is not writable; limiting disabled.');

            return 0;
        }

        $handle = @fopen($this->directory . '/' . hash('sha256', $bucket) . '.rl', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            error_log('[sustainable-web-analyzer] Rate limiter file is not writable; limiting disabled.');

            return 0;
        }

        try {
            $now = time();
            [$start, $count] = array_map('intval', explode(':', (string) stream_get_contents($handle)) + [0, 0]);

            if ($now - $start >= $this->window) {
                [$start, $count] = [$now, 0];
            }

            if ($count >= $limit) {
                return max(1, $start + $this->window - $now);
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $start . ':' . ($count + 1));
            fflush($handle);

            return 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
