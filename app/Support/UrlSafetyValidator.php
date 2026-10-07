<?php

namespace App\Support;

use App\Exceptions\UnsafeUrlException;

/**
 * Centralised SSRF protection for all outbound HTTP/DNS/TCP calls.
 *
 * Rules:
 *  - Only http:// and https:// schemes are allowed.
 *  - The URL host must resolve to a public IP (both A and AAAA records checked).
 *  - Literal IP addresses in the host are validated directly.
 *
 * By default the validator resolves hostnames via dns_get_record(). A custom
 * resolver closure can be injected (useful in unit tests):
 *
 *   UrlSafetyValidator::setResolver(fn(string $h, int $t) => [...records...]);
 */
class UrlSafetyValidator
{
    /**
     * Custom resolver: callable(string $host, int $type): array|false
     * Signature mirrors dns_get_record($host, $type).
     */
    private static ?\Closure $resolver = null;

    /**
     * Override the DNS resolver (useful in tests).
     *
     * @param  \Closure|null  $resolver  fn(string $host, int $type): array|false
     */
    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Return true when the URL is safe to make an outbound request to.
     */
    public static function isSafe(string $url): bool
    {
        try {
            self::assertSafe($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Assert that the URL is safe.
     *
     * @throws UnsafeUrlException when the URL targets a private/reserved address.
     */
    public static function assertSafe(string $url): void
    {
        // — Scheme check —
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException($url, "scheme '{$scheme}' is not allowed");
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            throw new UnsafeUrlException($url, 'missing host');
        }

        // Strip IPv6 brackets: [::1] → ::1
        $host = ltrim(rtrim($host, ']'), '[');

        // — Literal IP address —
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertIpSafe($host, $url);

            return;
        }

        // — DNS resolution —
        $ipsToCheck = self::resolveHost($host);

        if (empty($ipsToCheck)) {
            // No records → cannot confirm it is safe; block it.
            throw new UnsafeUrlException($url, "hostname '{$host}' did not resolve to any address");
        }

        foreach ($ipsToCheck as $ip) {
            self::assertIpSafe($ip, $url);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve A + AAAA records for a hostname and return a flat list of IPs.
     *
     * @return string[]
     */
    private static function resolveHost(string $host): array
    {
        $resolve = self::$resolver ?? static function (string $h, int $type): array|false {
            return @dns_get_record($h, $type);
        };

        $ips = [];

        $aRecords = $resolve($host, DNS_A);
        if (is_array($aRecords)) {
            foreach ($aRecords as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }

        $aaaaRecords = $resolve($host, DNS_AAAA);
        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Throw if the given IP falls in any blocked range.
     *
     * @throws UnsafeUrlException
     */
    private static function assertIpSafe(string $ip, string $originalUrl): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            self::assertIpv4Safe($ip, $originalUrl);
        } else {
            self::assertIpv6Safe($ip, $originalUrl);
        }
    }

    /**
     * @throws UnsafeUrlException
     */
    private static function assertIpv4Safe(string $ip, string $originalUrl): void
    {
        $ipLong = ip2long($ip);

        if ($ipLong === false) {
            // Unparseable — block to be safe.
            throw new UnsafeUrlException($originalUrl, "unparseable IPv4 address: {$ip}");
        }

        $blocked = [
            // Loopback 127.0.0.0/8
            ['network' => '127.0.0.0', 'mask' => '255.0.0.0'],
            // RFC 1918 — 10.0.0.0/8
            ['network' => '10.0.0.0', 'mask' => '255.0.0.0'],
            // RFC 1918 — 172.16.0.0/12
            ['network' => '172.16.0.0', 'mask' => '255.240.0.0'],
            // RFC 1918 — 192.168.0.0/16
            ['network' => '192.168.0.0', 'mask' => '255.255.0.0'],
            // Link-local (includes AWS/GCP/Ali cloud metadata 169.254.169.254)
            ['network' => '169.254.0.0', 'mask' => '255.255.0.0'],
            // AWS alternate metadata / Tencent 100.100.100.200
            ['network' => '100.64.0.0', 'mask' => '255.192.0.0'],
            // This host — 0.0.0.0/8
            ['network' => '0.0.0.0', 'mask' => '255.0.0.0'],
            // Broadcast
            ['network' => '255.255.255.255', 'mask' => '255.255.255.255'],
            // Multicast 224.0.0.0/4
            ['network' => '224.0.0.0', 'mask' => '240.0.0.0'],
        ];

        foreach ($blocked as ['network' => $network, 'mask' => $mask]) {
            $networkLong = ip2long($network);
            $maskLong = ip2long($mask);
            if (($ipLong & $maskLong) === ($networkLong & $maskLong)) {
                throw new UnsafeUrlException($originalUrl, "IP {$ip} is in a blocked range ({$network}/{$mask})");
            }
        }
    }

    /**
     * @throws UnsafeUrlException
     */
    private static function assertIpv6Safe(string $ip, string $originalUrl): void
    {
        // Normalise to binary for prefix matching.
        $binary = @inet_pton($ip);
        if ($binary === false) {
            throw new UnsafeUrlException($originalUrl, "unparseable IPv6 address: {$ip}");
        }

        // :: Loopback — ::1/128
        if ($ip === '::1' || $binary === inet_pton('::1')) {
            throw new UnsafeUrlException($originalUrl, "IP {$ip} is the IPv6 loopback");
        }

        // Multicast ff00::/8  (first byte 0xff)
        if (ord($binary[0]) === 0xFF) {
            throw new UnsafeUrlException($originalUrl, "IP {$ip} is in IPv6 multicast range (ff00::/8)");
        }

        // Link-local fe80::/10 (first 10 bits = 1111 1110 10)
        $firstByte = ord($binary[0]);
        $secondByte = ord($binary[1]);
        if ($firstByte === 0xFE && ($secondByte & 0xC0) === 0x80) {
            throw new UnsafeUrlException($originalUrl, "IP {$ip} is in IPv6 link-local range (fe80::/10)");
        }

        // ULA fc00::/7 (first 7 bits = 1111 110)
        if (($firstByte & 0xFE) === 0xFC) {
            throw new UnsafeUrlException($originalUrl, "IP {$ip} is in IPv6 ULA range (fc00::/7)");
        }

        // AWS IPv6 metadata fd00:ec2::254 is inside ULA, already covered above.
        // But also check the specific GCP/cloud fd00::/8 variants explicitly for clarity.
    }
}
