<?php

namespace App\Services\Checkers;

use App\Contracts\MonitorChecker;
use App\DTOs\CheckResult;
use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Exceptions\UnsafeUrlException;
use App\Models\Monitor;
use App\Support\UrlSafetyValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpChecker implements MonitorChecker
{
    public function check(Monitor $monitor): CheckResult
    {
        $startTime = microtime(true);
        $statusCode = null;
        $errorMessage = null;
        $status = CheckStatus::UP;
        $cause = null;

        try {
            UrlSafetyValidator::assertSafe($monitor->url);
        } catch (UnsafeUrlException $e) {
            return new CheckResult(
                status: CheckStatus::DOWN,
                responseTimeMs: 0,
                statusCode: null,
                sslExpiresAt: null,
                errorMessage: 'URL targets a private or reserved IP address',
                cause: IncidentCause::ERROR,
            );
        }

        try {
            $response = $this->makeHttpRequest($monitor);
            $statusCode = $response->status();

            if ($statusCode !== $monitor->expected_status_code) {
                $status = CheckStatus::DOWN;
                $cause = IncidentCause::STATUS_CODE;
            }

            if ($status === CheckStatus::UP && filled($monitor->keyword)) {
                if (! str_contains($response->body(), $monitor->keyword)) {
                    $status = CheckStatus::DOWN;
                    $cause = IncidentCause::KEYWORD;
                }
            }

            if ($status === CheckStatus::UP && filled($monitor->redirect_location_keyword)) {
                if ($statusCode >= 300 && $statusCode < 400) {
                    $location = $response->header('Location');
                    if (! str_contains((string) $location, $monitor->redirect_location_keyword)) {
                        $status = CheckStatus::DOWN;
                        $cause = IncidentCause::KEYWORD;
                    }
                }
            }
        } catch (ConnectionException $e) {
            $status = CheckStatus::DOWN;
            $errorMessage = $e->getMessage();

            $errorMsgLower = strtolower($errorMessage);
            if (str_contains($errorMsgLower, 'timeout')) {
                $cause = IncidentCause::TIMEOUT;
            } else {
                $cause = IncidentCause::ERROR;
            }
        }

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
        $sslExpiresAt = $this->checkSslExpiry($monitor->url);

        return new CheckResult(
            status: $status,
            responseTimeMs: $responseTimeMs,
            statusCode: $statusCode,
            sslExpiresAt: $sslExpiresAt,
            errorMessage: $errorMessage,
            cause: $cause,
        );
    }

    private function makeHttpRequest(Monitor $monitor)
    {
        $method = strtolower($monitor->method->value);

        $timeoutSeconds = $monitor->request_timeout_s ?? 15;

        // Custom headers (e.g. Referer for affiliate redirect guards behind a
        // gateway that returns 204 to referer-less requests) override the
        // defaults below when the same header name is set on both sides.
        $headers = array_merge([
            'User-Agent' => 'Up-Monitor/1.0 (+https://github.com/BBerthod/up-monitor)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ], $monitor->request_headers ?? []);

        $http = Http::timeout($timeoutSeconds)
            ->connectTimeout(min($timeoutSeconds, 10))
            ->withHeaders($headers);

        // TLS verification is enabled by default. Users who manage their own
        // certificates (e.g. self-signed) can opt out via the verify_tls flag.
        if ($monitor->verify_tls === false) {
            $http = $http->withoutVerifying();
        }

        // Redirect following is enabled by default. Monitors that need to assert
        // on a 3xx response (e.g. affiliate redirect guards) must opt out so that
        // the raw redirect status code and Location header are visible.
        if ($monitor->follow_redirects === false) {
            $http = $http->withOptions(['allow_redirects' => false]);
        }

        return $http->{$method}($monitor->url);
    }

    private function checkSslExpiry(string $url): ?\DateTime
    {
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'] ?? null;

            if (! $host) {
                return null;
            }

            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $socket = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (! $socket) {
                return null;
            }

            $params = stream_context_get_params($socket);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            fclose($socket);

            if (! $cert || ! isset($cert['validTo_time_t'])) {
                return null;
            }

            return (new \DateTime)->setTimestamp($cert['validTo_time_t']);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
