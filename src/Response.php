<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function send(bool $withBody = true): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Length: ' . strlen($this->body));

        if ($withBody) {
            echo $this->body;
        }
    }
}
