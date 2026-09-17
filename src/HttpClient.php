<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use CurlHandle;
use RuntimeException;

/**
 * Parallel HTTPS client on top of curl_multi.
 *
 * - Redirects are followed manually so every hop passes the UrlGuard again.
 * - Bodies are streamed: only HttpRequest::$maxBodyBytes are kept, the rest is counted and discarded.
 * - Every transfer ends at the shared deadline at the latest.
 */
final class HttpClient
{
    public function __construct(
        private readonly UrlGuard $guard,
        private readonly int $concurrency = 10,
        private readonly int $connectTimeoutMs = 3000,
        private readonly int $requestTimeoutMs = 8000,
        private readonly int $maxRedirects = 5,
        private readonly int $maxResponseBytes = 50_000_000,
        private readonly string $userAgent = 'SustainableWebAnalyzer',
        private readonly bool $verifyTls = true,
    ) {
    }

    /**
     * @param array<string, HttpRequest> $requests
     * @param float $deadline Unix timestamp (microtime) after which no transfer may run.
     * @return array<string, HttpResult> Same keys as $requests.
     */
    public function fetchAll(array $requests, float $deadline): array
    {
        $queue = [];
        foreach ($requests as $id => $request) {
            $queue[] = new Transfer((string) $id, $request);
        }

        $results = [];
        /** @var array<int, Transfer> $active keyed by spl_object_id of the cURL handle */
        $active = [];
        $multi = curl_multi_init();
        curl_multi_setopt($multi, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 6);

        try {
            while ($queue !== [] || $active !== []) {
                while ($queue !== [] && count($active) < $this->concurrency) {
                    $transfer = array_shift($queue);

                    try {
                        $handle = $this->createHandle($transfer, $deadline);
                    } catch (AnalyzerException $e) {
                        $results[$transfer->id] = $transfer->fail($e->detail !== '' ? $e->detail : $e->getMessage());
                        continue;
                    }

                    curl_multi_add_handle($multi, $handle);
                    $active[spl_object_id($handle)] = $transfer;
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK) {
                    throw new RuntimeException('curl_multi_exec failed: ' . curl_multi_strerror($status));
                }

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $transfer = $active[spl_object_id($handle)];
                    unset($active[spl_object_id($handle)]);
                    curl_multi_remove_handle($multi, $handle);

                    if ($this->complete($transfer, $info['result'])) {
                        $results[$transfer->id] = $transfer->result();
                    } else {
                        array_unshift($queue, $transfer); // follow redirect with priority
                    }
                }

                if ($active !== [] && curl_multi_select($multi, 0.25) === -1) {
                    usleep(5_000);
                }
            }
        } finally {
            curl_multi_close($multi);
        }

        return $results;
    }

    /**
     * @throws AnalyzerException when the URL is rejected by the guard or the deadline has passed.
     */
    private function createHandle(Transfer $transfer, float $deadline): CurlHandle
    {
        $remainingMs = (int) (($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            throw AnalyzerException::unreachable('Time limit reached');
        }

        $maxResponseBytes = $this->maxResponseBytes;

        $options = [
            CURLOPT_URL => $transfer->url,
            CURLOPT_HTTPHEADER => $transfer->request->headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->connectTimeoutMs, $remainingMs),
            CURLOPT_TIMEOUT_MS => min($this->requestTimeoutMs, $remainingMs),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($transfer): int {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) {
                    $transfer->status = (int) $match[1];
                    $transfer->location = null;
                    $transfer->contentLength = null;
                } elseif (($colon = strpos($line, ':')) !== false) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    if ($name === 'set-cookie') {
                        $transfer->setsCookie = true;
                    } elseif ($name === 'location') {
                        $transfer->location = trim(substr($line, $colon + 1));
                    } elseif ($name === 'content-length' && ctype_digit($value = trim(substr($line, $colon + 1)))) {
                        $transfer->contentLength = (int) $value;
                    }
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($transfer, $maxResponseBytes): int {
                $length = strlen($chunk);
                $transfer->bytes += $length;

                $room = $transfer->request->maxBodyBytes - strlen($transfer->body);
                if ($room > 0) {
                    $transfer->body .= $length <= $room ? $chunk : substr($chunk, 0, $room);
                }

                if ($transfer->bytes > $maxResponseBytes) {
                    $transfer->tooLarge = true;

                    return 0; // abort, the counted bytes are still reported
                }

                return $length;
            },
        ];

        if ($transfer->request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($transfer->request->guarded) {
            $options[CURLOPT_RESOLVE] = [$this->guard->pin($transfer->url)];
        }

        $handle = curl_init();
        if (!curl_setopt_array($handle, $options)) {
            throw AnalyzerException::unreachable('Could not configure cURL: ' . curl_error($handle));
        }

        return $handle;
    }

    /**
     * @return bool true when the transfer is finished, false when it must be re-queued for a redirect.
     */
    private function complete(Transfer $transfer, int $curlResult): bool
    {
        if ($curlResult !== CURLE_OK && !$transfer->tooLarge) {
            $transfer->error = curl_strerror($curlResult) ?? "cURL error {$curlResult}";

            return true;
        }

        if ($transfer->status < 300 || $transfer->status >= 400 || $transfer->location === null) {
            return true;
        }

        if ($transfer->redirects >= $this->maxRedirects) {
            $transfer->error = 'Too many redirects';

            return true;
        }

        $target = Url::resolve($transfer->url, $transfer->location);
        if ($target === null) {
            $transfer->error = 'Invalid redirect target';

            return true;
        }

        $transfer->redirectTo($target);

        return false;
    }
}
