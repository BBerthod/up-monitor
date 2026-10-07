<?php

namespace Tests\Feature\Jobs;

use App\Enums\ChannelType;
use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Jobs\Notifications\SendInsightAlert;
use App\Models\Insight;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Support\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for SendInsightAlert::handle().
 *
 * Http::fake() intercepts outgoing requests.
 * CACHE_STORE=array (phpunit.xml) — CircuitBreaker state is isolated per test
 * because Cache::flush() is called in setUp().
 */
class SendInsightAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset CircuitBreaker state so a tripped circuit in one test
        // does not bleed into the next (array cache is shared within a process).
        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // Helper: create a minimal Insight attached to a team.
    // -------------------------------------------------------------------------

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
    // 1. Slack — titleOverride is used in the header block text
    // -------------------------------------------------------------------------

    public function test_sends_slack_message_with_title_override(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->slack()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team, ['title' => 'Original title']);

        $titleOverride = '[Priority] Original title';
        $job = new SendInsightAlert($channel, $insight, $titleOverride);
        $job->handle();

        Http::assertSent(function ($request) use ($channel, $titleOverride) {
            if ($request->url() !== $channel->settings['webhook_url']) {
                return false;
            }

            $data = $request->data();
            // The title must appear in the 'header' block's text, not the original title.
            $headerText = $data['attachments'][0]['blocks'][0]['text']['text'] ?? '';

            return $headerText === $titleOverride;
        });
    }

    // -------------------------------------------------------------------------
    // 2. Slack — insight title used when no override given
    // -------------------------------------------------------------------------

    public function test_uses_insight_title_when_no_override(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->slack()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team, ['title' => 'Insight original title']);

        $job = new SendInsightAlert($channel, $insight, null);
        $job->handle();

        Http::assertSent(function ($request) use ($channel, $insight) {
            if ($request->url() !== $channel->settings['webhook_url']) {
                return false;
            }

            $headerText = $request->data()['attachments'][0]['blocks'][0]['text']['text'] ?? '';

            return $headerText === $insight->title;
        });
    }

    // -------------------------------------------------------------------------
    // 3. Circuit breaker open → handle() returns early, nothing sent
    // -------------------------------------------------------------------------

    public function test_skips_when_circuit_breaker_open(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->slack()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team);

        // Trip the circuit by recording 5 failures (default threshold = 5).
        $circuitKey = "notification:{$channel->id}";
        CircuitBreaker::recordFailure($circuitKey);
        CircuitBreaker::recordFailure($circuitKey);
        CircuitBreaker::recordFailure($circuitKey);
        CircuitBreaker::recordFailure($circuitKey);
        CircuitBreaker::recordFailure($circuitKey);

        $this->assertTrue(CircuitBreaker::isOpen($circuitKey));

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // 4. Webhook — payload structure: event, message, insight object
    // -------------------------------------------------------------------------

    public function test_webhook_payload_structure(): void
    {
        Http::fake();

        $team = Team::factory()->create();
        $channel = NotificationChannel::factory()->webhook()->create(['team_id' => $team->id]);
        $insight = $this->makeInsight($team);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertSent(function ($request) use ($channel, $insight) {
            if ($request->url() !== $channel->settings['url']) {
                return false;
            }

            $data = $request->data();

            return $data['event'] === 'insight.alert'
                && isset($data['message'])
                && isset($data['insight']['id'])
                && $data['insight']['id'] === $insight->id
                && $data['insight']['severity'] === $insight->severity->value;
        });
    }

    // -------------------------------------------------------------------------
    // 5. Telegram — missing credentials → nothing sent (early return)
    // -------------------------------------------------------------------------

    public function test_telegram_missing_credentials_does_not_send(): void
    {
        Http::fake();

        $team = Team::factory()->create();

        // Telegram channel with empty settings (no bot_token / chat_id).
        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'type' => ChannelType::TELEGRAM,
            'settings' => [],
        ]);

        $insight = $this->makeInsight($team);

        $job = new SendInsightAlert($channel, $insight);
        $job->handle();

        Http::assertNothingSent();
    }
}
