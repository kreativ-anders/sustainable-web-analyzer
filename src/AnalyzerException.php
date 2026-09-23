<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

use RuntimeException;

/**
 * An expected failure. The message is safe to show to end users (German, like the website);
 * $detail holds internal information that is only exposed in debug mode.
 */
final class AnalyzerException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, public readonly string $detail)
    {
        parent::__construct($message);
    }

    public static function invalidUrl(string $detail = ''): self
    {
        return new self('Der eingegebene Wert ist keine gültige URL.', 400, $detail);
    }

    public static function blocked(string $detail = ''): self
    {
        return new self('Die zu analysierende URL stellt ein Sicherheitsrisiko dar.', 422, $detail);
    }

    public static function unresolvable(string $detail = ''): self
    {
        return new self('Die Domain der Webseite konnte nicht gefunden werden.', 422, $detail);
    }

    public static function busy(string $detail = ''): self
    {
        return new self('Der CO2 Check ist gerade ausgelastet. Bitte versuche es in einer Minute erneut.', 503, $detail);
    }

    public static function unreachable(string $detail = ''): self
    {
        return new self('Die Webseite konnte nicht abgerufen werden.', 502, $detail);
    }
}
