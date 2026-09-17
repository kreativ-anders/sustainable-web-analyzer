<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * Green hosting status from The Green Web Foundation API v3.
 */
final class GreenCheck
{
    public function __construct(private readonly string $endpoint)
    {
    }

    public function request(string $host): HttpRequest
    {
        return new HttpRequest(
            rtrim($this->endpoint, '/') . '/' . rawurlencode($host),
            guarded: false,
            maxBodyBytes: 65_536,
            headers: ['Accept: application/json'],
        );
    }

    public function parse(?HttpResult $result): bool
    {
        if ($result === null || !$result->ok() || $result->status !== 200) {
            return false;
        }

        $data = json_decode($result->body, true);

        return is_array($data) && ($data['green'] ?? false) === true;
    }
}
