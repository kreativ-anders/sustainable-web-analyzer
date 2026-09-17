<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

final class HttpRequest
{
    /**
     * @param bool $guarded Run the SSRF guard (required for every user-controlled URL).
     * @param int $maxBodyBytes Bytes of the body kept in memory; the rest is only counted.
     * @param list<string> $headers
     * @param string $method GET or HEAD
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $guarded = true,
        public readonly int $maxBodyBytes = 0,
        public readonly array $headers = ['Accept: */*'],
        public readonly string $method = 'GET',
    ) {
    }

    public static function head(string $url): self
    {
        return new self($url, headers: ['Accept: */*'], method: 'HEAD');
    }
}
