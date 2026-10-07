<?php

namespace Tests\Unit\Services\Checkers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Checkers\DnsChecker;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class DnsCheckerTest extends TestCase
{
    use RefreshDatabase;

    private DnsChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new DnsChecker;
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        DnsChecker::setResolver(null);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────
    // SSRF guard
    // ──────────────────────────────────────────────────

    public function test_private_ip_as_domain_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns()->for($team)->create(['url' => '192.168.1.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(0, $result->responseTimeMs);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
        $this->assertStringContainsString('private', strtolower($result->errorMessage));
    }

    public function test_localhost_as_domain_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns()->for($team)->create(['url' => '127.0.0.1']);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // extractValue (white-box reflection tests)
    // ──────────────────────────────────────────────────

    public function test_extract_value_for_a_record(): void
    {
        $record = ['ip' => '93.184.216.34', 'host' => 'example.com', 'type' => 'A', 'ttl' => 300];
        $result = $this->invokeExtractValue($record, 'A');

        $this->assertEquals('93.184.216.34', $result);
    }

    public function test_extract_value_for_cname_record(): void
    {
        $record = ['target' => 'alias.example.com.', 'type' => 'CNAME', 'ttl' => 300];
        $result = $this->invokeExtractValue($record, 'CNAME');

        $this->assertEquals('alias.example.com.', $result);
    }

    public function test_extract_value_for_mx_record(): void
    {
        $record = ['target' => 'mail.example.com.', 'pri' => 10, 'type' => 'MX', 'ttl' => 300];
        $result = $this->invokeExtractValue($record, 'MX');

        $this->assertEquals('mail.example.com.', $result);
    }

    public function test_extract_value_for_txt_record(): void
    {
        $record = ['txt' => 'v=spf1 include:example.com ~all', 'entries' => [], 'type' => 'TXT', 'ttl' => 300];
        $result = $this->invokeExtractValue($record, 'TXT');

        $this->assertEquals('v=spf1 include:example.com ~all', $result);
    }

    // ──────────────────────────────────────────────────
    // valuesMatch — case and trailing-dot normalisation
    // ──────────────────────────────────────────────────

    public function test_values_match_is_case_insensitive(): void
    {
        $this->assertTrue($this->invokeValuesMatch('EXAMPLE.COM', 'example.com'));
    }

    public function test_values_match_ignores_trailing_dot(): void
    {
        $this->assertTrue($this->invokeValuesMatch('alias.example.com.', 'alias.example.com'));
    }

    public function test_values_match_returns_false_for_different_values(): void
    {
        $this->assertFalse($this->invokeValuesMatch('1.2.3.4', '5.6.7.8'));
    }

    // ──────────────────────────────────────────────────
    // Real DNS resolution — IANA example.com
    // ──────────────────────────────────────────────────

    /**
     * Resolves example.com using the system resolver.
     * IANA owns example.com and maintains a stable A record.
     * This test is intentionally lightweight and network-dependent.
     *
     * @group network
     */
    public function test_valid_a_record_for_example_com_returns_up(): void
    {
        // This was skipped in CI as "flaky ordering". The checker only compared
        // the first record, so a resolver that rotates a multi-record answer
        // really could flip the result between runs — that was a product bug,
        // now fixed by matching any returned record. The deterministic cases
        // for that live below; this one keeps a real end-to-end lookup.
        $records = @dns_get_record('example.com', DNS_A);

        if ($records === false || empty($records)) {
            $this->markTestSkipped('DNS resolution unavailable in this environment.');
        }

        // Bypass the SSRF guard in this test — we're validating DnsChecker
        // behaviour, not UrlSafetyValidator. The fake resolver returns a
        // known public IP so assertSafe passes regardless of runner DNS.
        UrlSafetyValidator::setResolver(
            // Must be dns_get_record-shaped: the validator reads ['ip'].
            fn (string $host, int $type): array => $type === DNS_A ? [['ip' => '93.184.216.34']] : [],
        );

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns('A', $records[0]['ip'])->for($team)->create([
            'url' => 'example.com',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
    }

    // ──────────────────────────────────────────────────
    // Multi-record answers (CDN A records, NS/MX/TXT sets)
    // ──────────────────────────────────────────────────

    public function test_it_matches_a_record_that_is_not_first_in_the_answer(): void
    {
        // A domain behind a CDN publishes several A records and the resolver
        // rotates them. Comparing only the first made a healthy domain flap.
        UrlSafetyValidator::setResolver(
            // Must be dns_get_record-shaped: the validator reads ['ip'].
            fn (string $host, int $type): array => $type === DNS_A ? [['ip' => '93.184.216.34']] : [],
        );
        DnsChecker::setResolver(fn () => [
            ['ip' => '104.20.23.154'],
            ['ip' => '172.66.147.243'],
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns('A', '172.66.147.243')->for($team)->create([
            'url' => 'cdn.example.com',
        ]);

        $this->assertEquals(CheckStatus::UP, $this->checker->check($monitor)->status);
    }

    public function test_it_matches_any_nameserver_in_the_set(): void
    {
        UrlSafetyValidator::setResolver(
            // Must be dns_get_record-shaped: the validator reads ['ip'].
            fn (string $host, int $type): array => $type === DNS_A ? [['ip' => '93.184.216.34']] : [],
        );
        DnsChecker::setResolver(fn () => [
            ['target' => 'ns1.example-dns.com'],
            ['target' => 'ns2.example-dns.com'],
            ['target' => 'ns3.example-dns.com'],
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns('NS', 'ns3.example-dns.com')->for($team)->create([
            'url' => 'example.com',
        ]);

        $this->assertEquals(CheckStatus::UP, $this->checker->check($monitor)->status);
    }

    public function test_it_reports_every_value_it_found_when_none_match(): void
    {
        UrlSafetyValidator::setResolver(
            // Must be dns_get_record-shaped: the validator reads ['ip'].
            fn (string $host, int $type): array => $type === DNS_A ? [['ip' => '93.184.216.34']] : [],
        );
        DnsChecker::setResolver(fn () => [
            ['ip' => '104.20.23.154'],
            ['ip' => '172.66.147.243'],
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns('A', '203.0.113.1')->for($team)->create([
            'url' => 'cdn.example.com',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        // The operator needs to see what is actually published, not one of them.
        $this->assertStringContainsString('104.20.23.154', (string) $result->errorMessage);
        $this->assertStringContainsString('172.66.147.243', (string) $result->errorMessage);
    }

    public function test_mismatched_a_record_returns_down(): void
    {
        $records = @dns_get_record('example.com', DNS_A);

        if ($records === false || empty($records)) {
            $this->markTestSkipped('DNS resolution unavailable in this environment.');
        }

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->dns('A', '1.2.3.4')->for($team)->create([
            'url' => 'example.com',
        ]);

        $result = $this->checker->check($monitor);

        // Only valid if the real IP is not 1.2.3.4.
        if ($records[0]['ip'] === '1.2.3.4') {
            $this->markTestSkipped('example.com resolved to 1.2.3.4 — value matches expected; test is inconclusive.');
        }

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertStringContainsString('mismatch', strtolower($result->errorMessage));
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function invokeExtractValue(array $record, string $type): string
    {
        $method = new ReflectionMethod(DnsChecker::class, 'extractValue');
        $method->setAccessible(true);

        return $method->invoke($this->checker, $record, $type);
    }

    private function invokeValuesMatch(string $actual, string $expected): bool
    {
        $method = new ReflectionMethod(DnsChecker::class, 'valuesMatch');
        $method->setAccessible(true);

        return $method->invoke($this->checker, $actual, $expected);
    }
}
