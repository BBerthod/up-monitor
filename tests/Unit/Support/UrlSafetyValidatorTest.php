<?php

namespace Tests\Unit\Support;

use App\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlSafetyValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Install a fake resolver so tests never hit real DNS.
        // By default resolve to a public IP — individual test cases override this.
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_A => [['ip' => '93.184.216.34']], // example.com
            default => [],
        });
    }

    protected function tearDown(): void
    {
        // Remove the fake resolver after each test.
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Safe URLs
    // -------------------------------------------------------------------------

    public function test_public_http_url_is_safe(): void
    {
        $this->assertTrue(UrlSafetyValidator::isSafe('http://example.com'));
    }

    public function test_public_https_url_is_safe(): void
    {
        $this->assertTrue(UrlSafetyValidator::isSafe('https://example.com/path?q=1'));
    }

    // -------------------------------------------------------------------------
    // Scheme blocking
    // -------------------------------------------------------------------------

    public function test_file_scheme_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('file:///etc/passwd'));
    }

    public function test_gopher_scheme_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('gopher://example.com'));
    }

    public function test_ftp_scheme_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('ftp://example.com'));
    }

    // -------------------------------------------------------------------------
    // Literal IPv4 — loopback (127.0.0.0/8)
    // -------------------------------------------------------------------------

    #[DataProvider('loopbackIpv4Provider')]
    public function test_loopback_ipv4_is_blocked(string $url): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe($url));
    }

    public static function loopbackIpv4Provider(): array
    {
        return [
            ['http://127.0.0.1'],
            ['http://127.0.0.2'],
            ['http://127.255.255.255'],
        ];
    }

    // -------------------------------------------------------------------------
    // Literal IPv4 — RFC 1918 private ranges
    // -------------------------------------------------------------------------

    #[DataProvider('rfc1918Provider')]
    public function test_rfc1918_ipv4_is_blocked(string $url): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe($url));
    }

    public static function rfc1918Provider(): array
    {
        return [
            ['http://10.0.0.1'],
            ['http://10.255.255.255'],
            ['http://172.16.0.1'],
            ['http://172.31.255.255'],
            ['http://192.168.0.1'],
            ['http://192.168.255.255'],
        ];
    }

    // -------------------------------------------------------------------------
    // Literal IPv4 — cloud metadata
    // -------------------------------------------------------------------------

    public function test_aws_metadata_ip_is_blocked(): void
    {
        // 169.254.169.254 — AWS/GCP/Azure IMDS
        $this->assertFalse(UrlSafetyValidator::isSafe('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_link_local_range_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://169.254.0.1'));
        $this->assertFalse(UrlSafetyValidator::isSafe('http://169.254.255.255'));
    }

    // -------------------------------------------------------------------------
    // Literal IPv4 — other reserved
    // -------------------------------------------------------------------------

    public function test_zero_address_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://0.0.0.0'));
    }

    public function test_broadcast_address_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://255.255.255.255'));
    }

    public function test_multicast_address_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://224.0.0.1'));
        $this->assertFalse(UrlSafetyValidator::isSafe('http://239.255.255.255'));
    }

    // -------------------------------------------------------------------------
    // IPv6 — loopback
    // -------------------------------------------------------------------------

    public function test_ipv6_loopback_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://[::1]'));
    }

    // -------------------------------------------------------------------------
    // IPv6 — link-local fe80::/10
    // -------------------------------------------------------------------------

    public function test_ipv6_link_local_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://[fe80::1]'));
    }

    // -------------------------------------------------------------------------
    // IPv6 — ULA fc00::/7
    // -------------------------------------------------------------------------

    #[DataProvider('ipv6UlaProvider')]
    public function test_ipv6_ula_is_blocked(string $url): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe($url));
    }

    public static function ipv6UlaProvider(): array
    {
        return [
            // fc00::/7 block
            ['http://[fc00::1]'],
            ['http://[fd00::1]'],
            // AWS IPv6 metadata fd00:ec2::254 (inside ULA)
            ['http://[fd00:ec2::254]'],
        ];
    }

    // -------------------------------------------------------------------------
    // IPv6 — multicast ff00::/8
    // -------------------------------------------------------------------------

    public function test_ipv6_multicast_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('http://[ff02::1]'));
    }

    // -------------------------------------------------------------------------
    // DNS-resolved private IPs
    // -------------------------------------------------------------------------

    public function test_hostname_resolving_to_private_ip_is_blocked(): void
    {
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_A => [['ip' => '192.168.1.1']],
            default => [],
        });

        $this->assertFalse(UrlSafetyValidator::isSafe('https://internal.example.com'));
    }

    public function test_hostname_resolving_to_metadata_ip_is_blocked(): void
    {
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_A => [['ip' => '169.254.169.254']],
            default => [],
        });

        $this->assertFalse(UrlSafetyValidator::isSafe('https://metadata.example.com'));
    }

    public function test_hostname_resolving_to_ipv6_ula_is_blocked(): void
    {
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_AAAA => [['ipv6' => 'fd00::1']],
            default => [],
        });

        $this->assertFalse(UrlSafetyValidator::isSafe('https://hidden.example.com'));
    }

    public function test_hostname_with_no_dns_records_is_blocked(): void
    {
        UrlSafetyValidator::setResolver(static fn (): array => []);

        $this->assertFalse(UrlSafetyValidator::isSafe('https://nxdomain.example.com'));
    }

    // -------------------------------------------------------------------------
    // assertSafe throws UnsafeUrlException
    // -------------------------------------------------------------------------

    public function test_assert_safe_throws_for_private_ip(): void
    {
        $this->expectException(UnsafeUrlException::class);

        UrlSafetyValidator::assertSafe('http://192.168.1.1');
    }

    public function test_assert_safe_throws_for_metadata_url(): void
    {
        $this->expectException(UnsafeUrlException::class);

        UrlSafetyValidator::assertSafe('http://169.254.169.254/latest/meta-data/');
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function test_missing_host_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('https://'));
    }

    public function test_url_without_scheme_is_blocked(): void
    {
        $this->assertFalse(UrlSafetyValidator::isSafe('example.com'));
    }

    public function test_100_64_cgnat_range_is_blocked(): void
    {
        // 100.64.0.0/10 — Carrier-Grade NAT (includes Alibaba 100.100.100.200)
        $this->assertFalse(UrlSafetyValidator::isSafe('http://100.64.0.1'));
        $this->assertFalse(UrlSafetyValidator::isSafe('http://100.100.100.200'));
    }
}
