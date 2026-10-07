<?php

namespace Tests\Unit\Services\Checkers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Checkers\HttpChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpCheckerTest extends TestCase
{
    use RefreshDatabase;

    private HttpChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = new HttpChecker;
    }

    // ──────────────────────────────────────────────────
    // Status code matching
    // ──────────────────────────────────────────────────

    public function test_http_200_with_expected_200_returns_up(): void
    {
        Http::fake(['https://example.com/*' => Http::response('OK', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertEquals(200, $result->statusCode);
        $this->assertNull($result->cause);
    }

    public function test_http_502_with_expected_200_returns_down(): void
    {
        Http::fake(['https://example.com/*' => Http::response('Bad Gateway', 502)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        // The real status code (502) must be stored — not a derived/default value.
        $this->assertEquals(502, $result->statusCode);
        $this->assertEquals(IncidentCause::STATUS_CODE, $result->cause);
    }

    public function test_http_404_with_expected_200_returns_down_with_actual_status_code(): void
    {
        Http::fake(['https://example.com/*' => Http::response('Not Found', 404)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(404, $result->statusCode);
        $this->assertEquals(IncidentCause::STATUS_CODE, $result->cause);
    }

    public function test_http_301_when_expected_301_returns_up(): void
    {
        Http::fake(['https://example.com/*' => Http::response('Moved', 301)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 301,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertEquals(301, $result->statusCode);
    }

    // ──────────────────────────────────────────────────
    // Keyword matching
    // ──────────────────────────────────────────────────

    public function test_keyword_present_in_body_returns_up(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('<html>Welcome to Example</html>', 200),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => 'Example',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertNull($result->cause);
    }

    public function test_keyword_absent_from_body_returns_down(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('<html>Maintenance page</html>', 200),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => 'Example',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(200, $result->statusCode);
        $this->assertEquals(IncidentCause::KEYWORD, $result->cause);
    }

    public function test_keyword_check_is_skipped_when_status_code_mismatch(): void
    {
        // Even if the keyword would be present, status code mismatch takes priority.
        Http::fake([
            'https://example.com/*' => Http::response('<html>Example body</html>', 503),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => 'Example',
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(503, $result->statusCode);
        $this->assertEquals(IncidentCause::STATUS_CODE, $result->cause);
    }

    public function test_null_keyword_skips_keyword_check(): void
    {
        Http::fake(['https://example.com/*' => Http::response('anything', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
    }

    // ──────────────────────────────────────────────────
    // Connection failures
    // ──────────────────────────────────────────────────

    public function test_connection_refused_returns_down_with_error_cause(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertNotEmpty($result->errorMessage);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
    }

    public function test_timeout_returns_down_with_timeout_cause(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Connection timeout after 15000 milliseconds');
        });

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertNotEmpty($result->errorMessage);
        $this->assertEquals(IncidentCause::TIMEOUT, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // SSRF guard
    // ──────────────────────────────────────────────────

    public function test_private_ip_url_returns_down_with_ssrf_error(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'http://192.168.1.1/',
            'expected_status_code' => 200,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(0, $result->responseTimeMs);
        $this->assertEquals(IncidentCause::ERROR, $result->cause);
        $this->assertStringContainsString('private', strtolower($result->errorMessage));
    }

    // ──────────────────────────────────────────────────
    // Response time is always measured
    // ──────────────────────────────────────────────────

    public function test_response_time_is_non_negative_on_success(): void
    {
        Http::fake(['https://example.com/*' => Http::response('OK', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertGreaterThanOrEqual(0, $result->responseTimeMs);
    }

    // ──────────────────────────────────────────────────
    // Redirect guard — follow_redirects=false
    // ──────────────────────────────────────────────────

    public function test_follow_redirects_false_sees_302_and_returns_up(): void
    {
        Http::fake(['https://example.com/*' => Http::response('', 302, ['Location' => 'https://www.amazon.fr/dp/B000001?tag=mytag-21'])]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/go/B000001',
            'expected_status_code' => 302,
            'follow_redirects' => false,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertEquals(302, $result->statusCode);
        $this->assertNull($result->cause);
    }

    public function test_follow_redirects_false_origin_returns_404_gives_down_status_code(): void
    {
        Http::fake(['https://example.com/*' => Http::response('Not Found', 404)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/go/B000001',
            'expected_status_code' => 302,
            'follow_redirects' => false,
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(404, $result->statusCode);
        $this->assertEquals(IncidentCause::STATUS_CODE, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // Redirect guard — redirect_location_keyword
    // ──────────────────────────────────────────────────

    public function test_redirect_location_keyword_present_returns_up(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('', 302, [
                'Location' => 'https://www.amazon.fr/dp/B000001?tag=mystore-21',
            ]),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/go/B000001',
            'expected_status_code' => 302,
            'follow_redirects' => false,
            'redirect_location_keyword' => 'amazon',
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertNull($result->cause);
    }

    public function test_redirect_location_keyword_absent_returns_down_keyword_cause(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('', 302, [
                'Location' => 'https://www.competitor.example.com/dp/B000001',
            ]),
        ]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/go/B000001',
            'expected_status_code' => 302,
            'follow_redirects' => false,
            'redirect_location_keyword' => 'amazon',
            'keyword' => null,
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::DOWN, $result->status);
        $this->assertEquals(302, $result->statusCode);
        $this->assertEquals(IncidentCause::KEYWORD, $result->cause);
    }

    // ──────────────────────────────────────────────────
    // Custom request headers (affiliate /go/ referer guard)
    // ──────────────────────────────────────────────────

    public function test_custom_request_header_is_sent_with_the_check(): void
    {
        Http::fake(['https://example.com/*' => Http::response('OK', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/go/B000001',
            'expected_status_code' => 200,
            'keyword' => null,
            'request_headers' => ['Referer' => 'https://example.com/'],
        ]);

        $this->checker->check($monitor);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Referer', 'https://example.com/');
        });
    }

    public function test_custom_request_header_overrides_default_header_of_the_same_name(): void
    {
        Http::fake(['https://example.com/*' => Http::response('OK', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
            'request_headers' => ['User-Agent' => 'CustomBot/1.0'],
        ]);

        $this->checker->check($monitor);

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', 'CustomBot/1.0');
        });
    }

    public function test_existing_monitor_without_new_fields_behaves_as_before(): void
    {
        // Regression guard: monitors created before this feature must keep working.
        Http::fake(['https://example.com/*' => Http::response('UP', 200)]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'url' => 'https://example.com/',
            'expected_status_code' => 200,
            'keyword' => null,
            // follow_redirects defaults to true, redirect_location_keyword is null
        ]);

        $result = $this->checker->check($monitor);

        $this->assertEquals(CheckStatus::UP, $result->status);
        $this->assertEquals(200, $result->statusCode);
        $this->assertNull($result->cause);
    }
}
