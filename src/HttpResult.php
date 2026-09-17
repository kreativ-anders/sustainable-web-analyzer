<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

final class HttpResult
{
    /**
     * @param string $url Final URL after redirects.
     * @param int $status HTTP status of the final response (0 if none was received).
     * @param int $bytes Body bytes as transferred (no Accept-Encoding is sent, so usually uncompressed).
     * @param bool $setsCookie Whether any response in the redirect chain sent Set-Cookie.
     * @param ?int $contentLength Content-Length header of the final response, if sent.
     * @param ?Limit $limit The gate that stopped the transfer: with an error it was not measured, without one its bytes are a lower bound.
     */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly int $bytes,
        public readonly string $body,
        public readonly bool $setsCookie,
        public readonly ?string $error,
        public readonly ?int $contentLength = null,
        public readonly ?Limit $limit = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status > 0;
    }
}
