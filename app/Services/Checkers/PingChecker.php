<?php

namespace App\Services\Checkers;

use App\Contracts\MonitorChecker;
use App\DTOs\CheckResult;
use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Exceptions\UnsafeUrlException;
use App\Models\Monitor;
use App\Support\UrlSafetyValidator;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PingChecker implements MonitorChecker
{
    public function check(Monitor $monitor): CheckResult
    {
        $host = $this->extractHost($monitor->url);
        $startTime = microtime(true);

        // SSRF guard — validate the hostname before invoking OS-level ping.
        // We reconstruct a fake URL so UrlSafetyValidator can parse the host.
        try {
            UrlSafetyValidator::assertSafe("https://{$host}");
        } catch (UnsafeUrlException) {
            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: 0,
                errorMessage: 'Host resolves to a private or reserved IP address',
                cause: IncidentCause::ERROR,
            );
        }

        try {
            // Use Symfony Process with a hard timeout for portability.
            // -c 3 = 3 packets, -W 5 = 5 s per-packet wait (Linux/BusyBox compatible).
            $process = new Process(['ping', '-c', '3', '-W', '5', $host]);
            $process->setTimeout(15);
            $process->run();

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $outputText = $process->getOutput().$process->getErrorOutput();

            $avgTime = $this->parseAveragePingTime($outputText);
            if ($avgTime !== null) {
                $responseTimeMs = $avgTime;
            }

            if ($process->isSuccessful() && str_contains($outputText, 'bytes from')) {
                return new CheckResult(
                    status: CheckStatus::UP,
                    responseTimeMs: $responseTimeMs,
                );
            }

            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: $responseTimeMs,
                errorMessage: '100% packet loss',
                cause: IncidentCause::TIMEOUT,
            );
        } catch (ProcessTimedOutException) {
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: $responseTimeMs,
                errorMessage: 'Ping timed out after 15 seconds',
                cause: IncidentCause::TIMEOUT,
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

    private function extractHost(string $url): string
    {
        $parsed = parse_url($url);

        return $parsed['host'] ?? $url;
    }

    private function parseAveragePingTime(string $output): ?int
    {
        // macOS/Linux: "round-trip min/avg/max/stddev = 1.234/5.678/9.012/1.234 ms"
        if (preg_match('/=\s*[\d.]+\/([\d.]+)\//', $output, $matches)) {
            return (int) round((float) $matches[1]);
        }

        return null;
    }
}
