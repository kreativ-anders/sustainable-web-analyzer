<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use Closure;

/**
 * Caps how many analyses run at the same time, one lock file per slot.
 *
 * The OS releases the lock when the process ends, so a killed worker cannot leak a slot. A request
 * that finds no slot is rejected rather than queued – queueing in front of a 20 s analysis is what
 * turns a burst into an outage.
 */
final class Semaphore
{
    public function __construct(private readonly string $directory, private readonly int $slots)
    {
    }

    /**
     * @template T
     *
     * @param Closure(): T $callback
     *
     * @return T
     *
     * @throws AnalyzerException 503 when every slot is busy
     */
    public function run(Closure $callback): mixed
    {
        if ($this->slots <= 0) {
            return $callback();
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('[sustainable-web-analyzer] Semaphore directory is not writable; concurrency is not capped.');

            return $callback();
        }

        for ($slot = 0; $slot < $this->slots; $slot++) {
            $handle = @fopen($this->directory . '/' . $slot . '.slot', 'c');

            if ($handle === false) {
                error_log('[sustainable-web-analyzer] Semaphore file is not writable; concurrency is not capped.');

                return $callback();
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                try {
                    return $callback();
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }

            fclose($handle);
        }

        throw AnalyzerException::busy("All {$this->slots} analysis slots are busy");
    }
}
