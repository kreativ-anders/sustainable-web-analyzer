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

        $handle = $this->open($bucket);
        if ($handle === null) {
            return 0; // fail open: a broken counter must not take the endpoint down
        }

        try {
            [$start, $count] = $this->read($handle);

            if ($count >= $limit) {
                return max(1, $start + $this->window - time());
            }

            $this->write($handle, $start, $count + 1);

            return 0;
        } finally {
            $this->close($handle);
        }
    }

    /**
     * @return int how many of $wanted units were granted (0 … $wanted)
     */
    public function take(string $bucket, int $limit, int $wanted): int
    {
        if ($limit <= 0) {
            return $wanted; // unlimited
        }
        if ($wanted <= 0) {
            return 0;
        }

        $handle = $this->open($bucket);
        if ($handle === null) {
            return $wanted;
        }

        try {
            [$start, $count] = $this->read($handle);
            $granted = max(0, min($wanted, $limit - $count));

            if ($granted > 0) {
                $this->write($handle, $start, $count + $granted);
            }

            return $granted;
        } finally {
            $this->close($handle);
        }
    }

    /**
     * @return array{0: int, 1: int} units left, and when the window resets (unix timestamp)
     */
    public function state(string $bucket, int $limit): array
    {
        $handle = $this->open($bucket);
        if ($handle === null) {
            return [$limit, time() + $this->window];
        }

        try {
            [$start, $count] = $this->read($handle);

            return [max(0, $limit - $count), $start + $this->window];
        } finally {
            $this->close($handle);
        }
    }

    /**
     * @return resource|null null when the counter cannot be used
     */
    private function open(string $bucket)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('[sustainable-web-analyzer] Rate limiter directory is not writable; limiting disabled.');

            return null;
        }

        $handle = @fopen($this->directory . '/' . hash('sha256', $bucket) . '.rl', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            error_log('[sustainable-web-analyzer] Rate limiter file is not writable; limiting disabled.');

            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     *
     * @return array{0: int, 1: int} window start and count, both 0 once the window has passed
     */
    private function read($handle): array
    {
        $now = time();
        [$start, $count] = array_map('intval', explode(':', (string) stream_get_contents($handle)) + [0, 0]);

        return $now - $start >= $this->window ? [$now, 0] : [$start, $count];
    }

    /**
     * @param resource $handle
     */
    private function write($handle, int $start, int $count): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $start . ':' . $count);
        fflush($handle);
    }

    /**
     * @param resource $handle
     */
    private function close($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
