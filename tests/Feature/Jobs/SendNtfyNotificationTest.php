<?php

namespace Tests\Feature\Jobs;

use App\Jobs\Notifications\SendNtfyNotification;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendNtfyNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // No real DNS in tests: every host resolves to a public address.
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => $type === DNS_A ? [['ip' => '93.184.216.34']] : []);
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    private function runJob(NotificationChannel $channel, string $event): void
    {
        $monitor = Monitor::factory()->create(['name' => 'Méth\'App', 'url' => 'https://example.com']);
        $incident = MonitorIncident::factory()->for($monitor)->create();

        (new SendNtfyNotification($channel, $event, $monitor, $incident))->handle();
    }

    public function test_on_call_channel_sends_down_alert_with_urgent_priority(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->ntfy(oncall: true)->create();

        $this->runJob($channel, 'down');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://ntfy.sh/'
                && $request['topic'] === 'up-test-alerts'
                && $request['priority'] === 5
                && $request['tags'] === ['rotating_light']
                && str_contains($request['title'], "Méth'App is Down");
        });
    }

    public function test_regular_channel_sends_down_alert_with_high_priority(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->ntfy()->create();

        $this->runJob($channel, 'down');

        Http::assertSent(fn (Request $request): bool => $request['priority'] === 4);
    }

    public function test_recovery_never_uses_urgent_priority_even_on_call(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->ntfy(oncall: true)->create();

        $this->runJob($channel, 'up');

        Http::assertSent(fn (Request $request): bool => $request['priority'] === 3
            && str_contains($request['title'], 'is Up'));
    }

    public function test_custom_server_and_token_are_used(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->create([
            'type' => 'ntfy',
            'settings' => ['topic' => 'alerts', 'server' => 'https://ntfy.example.org/', 'token' => 'tk_secret'],
        ]);

        $this->runJob($channel, 'down');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ntfy.example.org/'
            && $request->hasHeader('Authorization', 'Bearer tk_secret'));
    }

    public function test_private_server_is_blocked_by_the_ssrf_guard(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->create([
            'type' => 'ntfy',
            'settings' => ['topic' => 'alerts', 'server' => 'http://127.0.0.1:8080'],
        ]);

        $this->runJob($channel, 'down');

        Http::assertNothingSent();
    }

    public function test_channel_without_topic_sends_nothing(): void
    {
        Http::fake();
        $channel = NotificationChannel::factory()->create(['type' => 'ntfy', 'settings' => []]);

        $this->runJob($channel, 'down');

        Http::assertNothingSent();
    }

    public function test_http_error_is_raised_so_the_job_retries(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $channel = NotificationChannel::factory()->ntfy(oncall: true)->create();

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->runJob($channel, 'down');
    }

    public function test_redirects_are_not_followed_so_a_public_server_cannot_bounce_to_a_private_address(): void
    {
        Http::fake([
            'ntfy.example.org/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
            '169.254.169.254/*' => Http::response('secret', 200),
        ]);
        $channel = NotificationChannel::factory()->create([
            'type' => 'ntfy',
            'settings' => ['topic' => 'alerts', 'server' => 'https://ntfy.example.org'],
        ]);

        try {
            $this->runJob($channel, 'down');
        } catch (\Illuminate\Http\Client\RequestException) {
            // A 302 is an error response for ->throw(); what matters is the next assertion.
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
    }
}
