<?php

namespace App\Services\Checkers;

use App\Contracts\MonitorChecker;
use App\DTOs\CheckResult;
use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Exceptions\UnsafeUrlException;
use App\Models\Monitor;
use App\Support\UrlSafetyValidator;

class PortChecker implements MonitorChecker
{
    public function check(Monitor $monitor): CheckResult
    {
        $host = $this->extractHost($monitor->url);
        $port = $monitor->port;
        $startTime = microtime(true);

        // SSRF guard — validate the hostname before opening a TCP socket.
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
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
            );

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($socket) {
                fclose($socket);

                return new CheckResult(
                    status: CheckStatus::UP,
                    responseTimeMs: $responseTimeMs,
                );
            }

            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: $responseTimeMs,
                errorMessage: $errstr ?: "Connection refused on port {$port}",
                cause: str_contains(strtolower($errstr), 'timeout')
                    ? IncidentCause::TIMEOUT
                    : IncidentCause::ERROR,
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
}
