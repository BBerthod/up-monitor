<?php

namespace Tests\Feature\Security;

use App\Enums\ChannelType;
use App\Enums\IncidentCause;
use App\Jobs\Notifications\SendWebhookNotification;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookHmacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Inject a fake DNS resolver so webhook.example.com resolves to a public IP
        // without making real DNS queries (which would fail in CI / offline environments).
        UrlSafetyValidator::setResolver(static fn (string $host, int $type): array => match ($type) {
            DNS_A => [['ip' => '93.184.216.34']],
            default => [],
        });
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);

        parent::tearDown();
    }

    private function makeJob(array $settings): SendWebhookNotification
    {
        $team = Team::factory()->create();

        $channel = NotificationChannel::factory()->create([
            'team_id' => $team->id,
            'type' => ChannelType::WEBHOOK,
            'settings' => $settings,
            'is_active' => true,
        ]);

        $monitor = Monitor::factory()->create(['team_id' => $team->id]);

        $incident = MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::STATUS_CODE,
            'started_at' => now(),
        ]);

        $check = MonitorCheck::factory()->create([
            'monitor_id' => $monitor->id,
        ]);

        return new SendWebhookNotification($channel, 'down', $monitor, $incident, $check);
    }

    public function test_webhook_with_secret_sends_x_up_signature_header(): void
    {
        $secret = 'super-secret-key';

        Http::fake(['https://webhook.example.com' => Http::response('OK', 200)]);

        $job = $this->makeJob([
            'url' => 'https://webhook.example.com',
            'secret' => $secret,
        ]);

        $job->handle();

        Http::assertSent(function ($request) use ($secret) {
            $signature = $request->header('X-Up-Signature')[0] ?? null;

            if ($signature === null) {
                return false;
            }

            // Verify the signature matches the body
            $body = $request->body();
            $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

            return $signature === $expected;
        });
    }

    public function test_webhook_without_secret_does_not_send_signature_header(): void
    {
        Http::fake(['https://webhook.example.com' => Http::response('OK', 200)]);

        $job = $this->makeJob([
            'url' => 'https://webhook.example.com',
        ]);

        $job->handle();

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('X-Up-Signature');
        });
    }

    public function test_webhook_with_empty_secret_does_not_send_signature_header(): void
    {
        Http::fake(['https://webhook.example.com' => Http::response('OK', 200)]);

        $job = $this->makeJob([
            'url' => 'https://webhook.example.com',
            'secret' => '',
        ]);

        $job->handle();

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('X-Up-Signature');
        });
    }

    public function test_webhook_with_null_secret_does_not_send_signature_header(): void
    {
        Http::fake(['https://webhook.example.com' => Http::response('OK', 200)]);

        $job = $this->makeJob([
            'url' => 'https://webhook.example.com',
            'secret' => null,
        ]);

        $job->handle();

        Http::assertSent(function ($request) {
            return ! $request->hasHeader('X-Up-Signature');
        });
    }

    public function test_webhook_channel_accepts_optional_secret_in_validation(): void
    {
        $team = Team::factory()->create();
        $user = \App\Models\User::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->post(route('channels.store'), [
            'name' => 'My Webhook',
            'type' => 'webhook',
            'settings' => [
                'url' => 'https://webhook.example.com',
                'secret' => 'my-hmac-secret',
            ],
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('notification_channels', [
            'team_id' => $team->id,
            'name' => 'My Webhook',
        ]);

        $channel = NotificationChannel::where('name', 'My Webhook')->first();
        $this->assertSame('my-hmac-secret', $channel->settings['secret']);
    }

    public function test_webhook_channel_can_be_created_without_secret(): void
    {
        $team = Team::factory()->create();
        $user = \App\Models\User::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->post(route('channels.store'), [
            'name' => 'Webhook No Secret',
            'type' => 'webhook',
            'settings' => [
                'url' => 'https://webhook.example.com',
            ],
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('notification_channels', [
            'name' => 'Webhook No Secret',
        ]);
    }
}
