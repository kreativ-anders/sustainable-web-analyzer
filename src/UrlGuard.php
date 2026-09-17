<?php

declare(strict_types=1);

namespace SustainableWebAnalyzer;

/**
 * Protects against server-side request forgery (SSRF).
 *
 * Only https URLs on port 443 without credentials are allowed. Hosts are resolved once and
 * every resolved address must be globally routable; the vetted IP is then pinned for cURL
 * (CURLOPT_RESOLVE) so a second DNS answer cannot point the request somewhere else.
 */
final class UrlGuard
{
    private const MAX_URL_LENGTH = 2048;

    /** Not globally routable, but not rejected by FILTER_FLAG_GLOBAL_RANGE (PHP 8.2 – 8.5). */
    private const NON_GLOBAL_RANGES = [
        '224.0.0.0/4',     // IPv4 multicast
        '192.88.99.0/24',  // deprecated 6to4 relay anycast
        'ff00::/8',        // IPv6 multicast
        'fec0::/10',       // deprecated site-local, still routed internally in some networks
        '5f00::/16',       // SRv6 SIDs (RFC 9602)
        '3fff::/20',       // documentation (RFC 9637)
    ];

    /** @var array<string, string|AnalyzerException> host => IP or the failure */
    private array $resolved = [];

    /**
     * Turn user input into a canonical https URL.
     *
     * @throws AnalyzerException
     */
    public static function normalizeInput(string $input): string
    {
        $input = trim($input);

        if ($input === '' || strlen($input) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $input)) {
            throw AnalyzerException::invalidUrl('Empty, too long or contains whitespace/control characters');
        }

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $input)) {
            $input = 'https://' . $input;
        }

        $parts = parse_url($input);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw AnalyzerException::invalidUrl('Unparsable URL');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw AnalyzerException::invalidUrl('Unsupported scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw AnalyzerException::invalidUrl('Credentials are not allowed');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw AnalyzerException::blocked('Only port 443 is allowed');
        }

        $host = self::normalizeHost($parts['host']);
        if ($host === null) {
            throw AnalyzerException::invalidUrl('Invalid host');
        }

        $url = Url::normalize('https://' . $host . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''));

        return $url ?? throw AnalyzerException::invalidUrl('Invalid URL');
    }

    /**
     * Validate a URL right before it is requested and return the CURLOPT_RESOLVE entry pinning its IP.
     *
     * @throws AnalyzerException
     */
    public function pin(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            throw AnalyzerException::blocked('Only https URLs are allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw AnalyzerException::blocked('Credentials are not allowed');
        }
        if (isset($parts['port']) && $parts['port'] !== 443) {
            throw AnalyzerException::blocked('Only port 443 is allowed');
        }

        $host = self::normalizeHost($parts['host']) ?? throw AnalyzerException::blocked('Invalid host');
        $ip = $this->resolve($host);

        return $host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
    }

    /**
     * Resolve a host to a single public IP (IPv4 preferred). Results are memoized per instance.
     *
     * @throws AnalyzerException
     */
    public function resolve(string $host): string
    {
        $result = $this->resolved[$host] ??= $this->lookup($host);

        if ($result instanceof AnalyzerException) {
            throw $result;
        }

        return $result;
    }

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $ip = (string) inet_ntop((string) inet_pton($ip));

            // IPv4-mapped addresses are checked as IPv4; NAT64 and 6to4 may tunnel to internal IPv4 ranges.
            if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $match)) {
                $ip = $match[1];
            } elseif (preg_match('/^(64:ff9b:|2002:)/i', $ip)) {
                return false;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        foreach (self::NON_GLOBAL_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $address = (string) inet_pton($ip);
        $network = (string) inet_pton($subnet);

        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv((int) $bits, 8);
        $remainder = (int) $bits % 8;

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;

        return $remainder === 0 || (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private static function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        if ($host === '' || str_starts_with($host, '[')) {
            return null; // IPv6 literals are not supported
        }

        if (preg_match('/[^\x21-\x7e]/', $host)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $host = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) {
                return null;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || !str_contains($host, '.')
            || preg_match('/(^|\.)(localhost|local|localdomain|internal|intranet|lan|home\.arpa)$/', $host)
        ) {
            return null;
        }

        return $host;
    }

    private function lookup(string $host): string|AnalyzerException
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = @gethostbynamel($host) ?: [];

            if ($ips === []) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if ($ips === []) {
            return AnalyzerException::unresolvable("Could not resolve {$host}");
        }

        // Reject the host if *any* address is internal, so round-robin answers cannot be abused.
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return AnalyzerException::blocked("{$host} resolves to non-public address {$ip}");
            }
        }

        return $ips[0];
    }
}
