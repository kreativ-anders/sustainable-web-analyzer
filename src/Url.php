<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * URL helpers. Every URL produced here is absolute and uses https:// – plain http
 * references are upgraded, other schemes (data:, blob:, javascript:, …) are dropped.
 */
final class Url
{
    /**
     * Resolve a reference found in HTML or a Location header against a base URL (RFC 3986, 5.2).
     */
    public static function resolve(string $base, string $reference): ?string
    {
        // Browsers strip surrounding whitespace and ignore tabs/newlines inside URLs.
        $reference = str_replace(["\t", "\n", "\r"], '', trim($reference, " \t\n\r\f"));
        $reference = explode('#', $reference, 2)[0];

        if ($reference === '') {
            return null;
        }

        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $reference, $match)) {
            $scheme = strtolower($match[1]);
            $rest = substr($reference, strlen($match[0]));

            if (($scheme !== 'https' && $scheme !== 'http') || !str_starts_with($rest, '//')) {
                return null;
            }

            return self::normalize('https:' . $rest);
        }

        if (str_starts_with($reference, '//')) {
            return self::normalize('https:' . $reference);
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || !isset($baseParts['host'])) {
            return null;
        }

        [$path, $query] = array_pad(explode('?', $reference, 2), 2, null);
        $basePath = $baseParts['path'] ?? '/';

        if ($path === '') {
            $path = $basePath;
            $query ??= $baseParts['query'] ?? null;
        } elseif ($path[0] !== '/') {
            $path = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $path;
        }

        $authority = $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');

        return self::normalize('https://' . $authority . $path . ($query !== null ? '?' . $query : ''));
    }

    /**
     * Canonical form: https, lower-case host, dot segments removed, non-ASCII percent-encoded.
     */
    public static function normalize(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $url = 'https://';
        if (isset($parts['user'])) {
            $url .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $url .= strtolower($parts['host']);
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= self::encode(self::removeDotSegments($parts['path'] ?? '/'));
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . self::encode($parts['query']);
        }

        return $url;
    }

    /**
     * RFC 3986, 5.2.4 – for absolute paths.
     */
    public static function removeDotSegments(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        $segments = explode('/', $path);
        $last = end($segments);
        $output = [];

        foreach ($segments as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                continue;
            }
            $output[] = $segment;
        }

        if ($last === '.' || $last === '..') {
            $output[] = '';
        }

        $result = implode('/', $output);

        return $result === '' ? '/' : $result;
    }

    /**
     * Lower-case file extension of the URL path ('' if there is none).
     */
    public static function extension(string $url): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : '';
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private static function encode(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^\x21-\x7e]/',
            static fn (array $match): string => rawurlencode($match[0]),
            $value,
        );
    }
}
