<?php

namespace App\Services\Checkers;

use App\Contracts\MonitorChecker;
use App\DTOs\CheckResult;
use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Exceptions\UnsafeUrlException;
use App\Models\Monitor;
use App\Support\UrlSafetyValidator;

/**
 * By default records are read with dns_get_record(). A resolver closure can be
 * injected so tests can assert on record sets without depending on live DNS —
 * same mechanism UrlSafetyValidator offers.
 *
 *   DnsChecker::setResolver(fn (string $host, int $type) => [...records...]);
 */
class DnsChecker implements MonitorChecker
{
    /** Custom resolver: callable(string $host, int $type): array|false */
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

    private const RECORD_TYPE_MAP = [
        'A' => DNS_A,
        'AAAA' => DNS_AAAA,
        'CNAME' => DNS_CNAME,
        'MX' => DNS_MX,
        'TXT' => DNS_TXT,
        'NS' => DNS_NS,
        'SOA' => DNS_SOA,
        'SRV' => DNS_SRV,
    ];

    public function check(Monitor $monitor): CheckResult
    {
        $domain = $this->extractDomain($monitor->url);
        $recordType = $monitor->dns_record_type;
        $expectedValue = $monitor->dns_expected_value;
        $startTime = microtime(true);

        // SSRF guard — validate the domain before issuing the DNS lookup.
        // dns_get_record() itself is used for the check, but we must ensure
        // the domain is not a literal private IP masquerading as a hostname.
        try {
            UrlSafetyValidator::assertSafe("https://{$domain}");
        } catch (UnsafeUrlException $e) {
            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: 0,
                errorMessage: 'Domain resolves to a private or reserved IP address',
                cause: IncidentCause::ERROR,
            );
        }

        try {
            $dnsType = self::RECORD_TYPE_MAP[$recordType] ?? DNS_A;
            // dns_get_record() is inherently blocking; there is no portable
            // pure-PHP timeout mechanism. We use @-suppression so that a
            // resolver timeout returns false (handled below) rather than a
            // PHP warning. A 10-second PHP default resolver timeout applies.
            $records = self::$resolver !== null
                ? (self::$resolver)($domain, $dnsType)
                : @dns_get_record($domain, $dnsType);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($records === false || empty($records)) {
                return new CheckResult(
                    status: CheckStatus::DOWN,
                    responseTimeMs: $responseTimeMs,
                    errorMessage: "No {$recordType} record found for {$domain}",
                    cause: IncidentCause::ERROR,
                );
            }

            // Match against EVERY returned record, not just the first.
            //
            // Most record types are plural in practice: a domain behind a CDN
            // publishes several A records, NS and MX are always a set, and TXT
            // holds SPF, DKIM and verification strings side by side. Resolvers
            // rotate the order between queries, so comparing $records[0] made
            // the check depend on which record happened to come back first —
            // the same healthy domain would flap UP and DOWN between runs and
            // raise incidents nobody could reproduce.
            $actualValues = array_values(array_filter(array_map(
                fn (array $record): string => $this->extractValue($record, $recordType),
                $records,
            ), static fn (string $value): bool => $value !== ''));

            foreach ($actualValues as $actualValue) {
                if ($this->valuesMatch($actualValue, $expectedValue)) {
                    return new CheckResult(
                        status: CheckStatus::UP,
                        responseTimeMs: $responseTimeMs,
                    );
                }
            }

            $found = $actualValues === [] ? 'nothing' : "'".implode("', '", $actualValues)."'";

            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: $responseTimeMs,
                errorMessage: "DNS mismatch: expected '{$expectedValue}', got {$found}",
                cause: IncidentCause::ERROR,
            );
        } catch (\Throwable $e) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: $responseTimeMs,
                errorMessage: $e->getMessage(),
                cause: IncidentCause::ERROR,
            );
        }
    }

    private function extractDomain(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? $url;
    }

    private function extractValue(array $record, string $type): string
    {
        return match ($type) {
            'A', 'AAAA' => $record['ip'] ?? '',
            'CNAME' => $record['target'] ?? '',
            'MX' => $record['target'] ?? '',
            'TXT' => $record['txt'] ?? '',
            'NS' => $record['target'] ?? '',
            'SOA' => $record['mname'] ?? '',
            'SRV' => ($record['target'] ?? '').':'.($record['port'] ?? ''),
            default => $record['ip'] ?? $record['target'] ?? '',
        };
    }

    private function valuesMatch(string $actual, string $expected): bool
    {
        return strcasecmp(rtrim($actual, '.'), rtrim($expected, '.')) === 0;
    }
}
