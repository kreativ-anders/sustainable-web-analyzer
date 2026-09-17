<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * Mutable state of one request while HttpClient runs it (including its redirect hops).
 *
 * @internal
 */
final class Transfer
{
    public string $url;
    public int $redirects = 0;
    public int $status = 0;
    public int $bytes = 0;
    public string $body = '';
    public ?string $location = null;
    public ?int $contentLength = null;
    public bool $setsCookie = false;
    public bool $tooLarge = false;
    public ?string $error = null;

    public function __construct(public readonly string $id, public readonly HttpRequest $request)
    {
        $this->url = $request->url;
    }

    public function redirectTo(string $url): void
    {
        $this->url = $url;
        $this->redirects++;
        $this->status = 0;
        $this->bytes = 0;
        $this->body = '';
        $this->location = null;
        $this->contentLength = null;
        $this->tooLarge = false;
    }

    public function fail(string $error): HttpResult
    {
        $this->error = $error;

        return $this->result();
    }

    public function result(): HttpResult
    {
        return new HttpResult($this->url, $this->status, $this->bytes, $this->body, $this->setsCookie, $this->error, $this->contentLength);
    }
}
