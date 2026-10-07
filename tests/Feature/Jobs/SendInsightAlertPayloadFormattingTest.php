<?php

namespace Tests\Feature\Jobs;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\Notifications\SendInsightAlert;
use App\Mail\InsightAlertMail;
use App\Models\Insight;
use App\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for the perf_regression "Array to string conversion" bug.
 *
 * PerfRegressionDetector stores payload['previous']/['current'] as metric-shaped
 * associative arrays (performance/lcp/cls/scored_at), not scalars. Every channel
 * builder in SendInsightAlert — and the mail Blade view — used to interpolate or
 * (string) cast those values directly, which PHP turns into an "Array to string
 * conversion" warning that Laravel promotes to an ErrorException. That silently
 * killed every perf_regression alert on every channel before
 * SendInsightAlert::formatPayloadValue() existed.
 */
class SendInsightAlertPayloadFormattingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate CircuitBreaker state between tests (array cache is shared within a process).
        Cache::flush();
    }

    private function makeInsight(Team $team, array $overrides = []): Insight
    {
        return Insight::factory()->create(array_merge([
            'team_id' => $team->id,
            'type' => InsightType::TRAFFIC_CHANGE->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'Traffic drop detected',
            'site' => 'example.com',
            'payload' => [],
            'notified_at' => null,
            'acknowledged_at' => null,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // 1. Telegram — array-shaped previous/current (perf_regression) must not throw
    //    and must render a short, human-readable metrics summary.
    // -------------------------------------------------------------------------

    public function test_telegram_formats_array_shaped_perf_regression_payload(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->telegram()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team, [
            'type' => InsightType::PERF_REGRESSION->value,
            'title' => 'Performance regression detected',
            'payload' => [
                'previous' => [
                    'performance' => 92,
                    'lcp' => 2100,
                    'cls' => 0.02,
                    'scored_at' => '2026-08-10T18:09:47+00:00',
                ],
                'current' => [
                    'performance' => 42,
                    'lcp' => 16516,
                    'cls' => 0.305,
                    'scored_at' => '2026-08-11T18:09:47+00:00',
                ],
            ],
        ]);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }

            $text = $request->data()['text'] ?? '';

            // scored_at must be dropped, metrics must be labelled and LCP converted to seconds.
            return str_contains($text, 'perf 42, LCP 16.5s, CLS 0.305')
                && str_contains($text, 'perf 92, LCP 2.1s, CLS 0.02')
                && ! str_contains($text, 'scored_at')
                && ! str_contains($text, 'Array');
        });
    }

    // -------------------------------------------------------------------------
    // 2. Email — same array-shaped payload must not raise a ViewException when
    //    the Blade view renders, and must show the same short summary.
    // -------------------------------------------------------------------------

    public function test_email_formats_array_shaped_perf_regression_payload(): void
    {
        Mail::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'settings' => ['recipients' => 'ops@example.com'],
        ]);
        $insight = $this->makeInsight($team, [
            'type' => InsightType::PERF_REGRESSION->value,
            'title' => 'Performance regression detected',
            'payload' => [
                'previous' => ['performance' => 92, 'lcp' => 2100, 'cls' => 0.02],
                'current' => ['performance' => 42, 'lcp' => 16516, 'cls' => 0.305],
            ],
        ]);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Mail::assertSent(InsightAlertMail::class, function (InsightAlertMail $mail) {
            // Rendering the Blade view is what reproduces the original
            // "htmlspecialchars(): Argument #1 must be of type string, array given".
            $mail->assertSeeInHtml('perf 42, LCP 16.5s, CLS 0.305');
            $mail->assertSeeInHtml('perf 92, LCP 2.1s, CLS 0.02');

            return true;
        });
    }

    // -------------------------------------------------------------------------
    // 3. Telegram — scalar previous/current (e.g. HealthScoreService-style insights)
    //    must keep rendering exactly as before.
    // -------------------------------------------------------------------------

    public function test_telegram_formats_scalar_previous_current_unchanged(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->telegram()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team, [
            'payload' => ['previous' => 72, 'current' => 55],
        ]);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertSent(function ($request) {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'Before → After:</b> 72 → 55');
        });
    }

    // -------------------------------------------------------------------------
    // 4. Telegram — payload['sites'] (server-health) must keep rendering the
    //    "Affects" line with the existing "+N more" truncation.
    // -------------------------------------------------------------------------

    public function test_telegram_formats_sites_list_unchanged(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->telegram()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team, [
            'type' => InsightType::SERVER_HEALTH->value,
            'payload' => [
                'sites' => ['a.com', 'b.com', 'c.com', 'd.com', 'e.com', 'f.com'],
            ],
        ]);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertSent(function ($request) {
            $text = $request->data()['text'] ?? '';

            return str_contains($text, 'Affects:</b> a.com, b.com, c.com, d.com, e.com +1 more');
        });
    }
}
