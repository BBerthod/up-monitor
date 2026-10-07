<?php

namespace Tests\Feature\Services;

use App\Enums\KpiRegressionSeverity;
use App\Enums\KpiSource;
use App\Models\BusinessKpiIncident;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\Team;
use App\Services\KpiRegressionDetector;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * KPI regression notifications must only reach the team that owns the site.
 *
 * They used to broadcast to every active channel of every team "as a first
 * pass" — a cross-tenant leak.
 */
class KpiRegressionNotificationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function makeIncident(string $site): BusinessKpiIncident
    {
        return BusinessKpiIncident::create([
            'site' => $site,
            'source' => KpiSource::GSC->value,
            'metric' => 'clicks_28d',
            'severity' => KpiRegressionSeverity::MAJOR,
            'baseline_value' => 100,
            'current_value' => 10,
            'delta_pct' => -90,
            'detected_at' => now(),
        ]);
    }

    private function notify(BusinessKpiIncident $incident, ?int $teamId = null): void
    {
        $method = new ReflectionMethod(KpiRegressionDetector::class, 'sendIncidentNotification');
        $method->invoke(app(KpiRegressionDetector::class), $incident, $teamId);
    }

    public function test_only_the_owning_team_channels_are_notified(): void
    {
        $owner = Team::factory()->create();
        $other = Team::factory()->create();

        Site::factory()->for($owner)->create([
            'primary_domain' => 'owned.example.com',
            'domains' => ['owned.example.com'],
        ]);

        $ownerChannel = NotificationChannel::factory()->for($owner)->create(['is_active' => true]);
        $otherChannel = NotificationChannel::factory()->for($other)->create(['is_active' => true]);

        $notified = [];
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyBusinessKpi')
            ->andReturnUsing(function (NotificationChannel $channel) use (&$notified) {
                $notified[] = $channel->id;
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->notify($this->makeIncident('owned.example.com'));

        $this->assertContains($ownerChannel->id, $notified);
        $this->assertNotContains($otherChannel->id, $notified, 'Channels of other teams must never be notified.');
    }

    public function test_unknown_site_fails_closed_and_notifies_nobody(): void
    {
        $team = Team::factory()->create();
        NotificationChannel::factory()->for($team)->create(['is_active' => true]);

        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldNotReceive('notifyBusinessKpi');
        $this->app->instance(NotificationService::class, $mock);

        $incident = $this->makeIncident('nobody-owns-this.example.net');
        $this->notify($incident);

        // Not marked notified either — the incident stays visible as pending.
        $this->assertNull($incident->fresh()->notification_sent_at);
    }

    public function test_locale_template_domains_resolve_to_their_owner(): void
    {
        $owner = Team::factory()->create();

        Site::factory()->for($owner)->create([
            'primary_domain' => 'fr.multi.example.com',
            'domains' => ['{locale}.multi.example.com'],
            'locales' => ['fr', 'de'],
            'primary_locale' => 'fr',
        ]);

        $channel = NotificationChannel::factory()->for($owner)->create(['is_active' => true]);

        $notified = [];
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyBusinessKpi')
            ->andReturnUsing(function (NotificationChannel $c) use (&$notified) {
                $notified[] = $c->id;
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->notify($this->makeIncident('de.multi.example.com'));

        $this->assertSame([$channel->id], $notified);
    }

    public function test_an_explicit_team_is_used_when_no_site_matches(): void
    {
        // Monitors with no Site row are a supported state (OrphanMonitorDetector
        // exists for exactly them) and their KPI key matches no Site. The
        // collection job knows the owning team, so the alert must still be
        // delivered rather than silently dropped by the fail-closed branch.
        $owner = Team::factory()->create();
        $other = Team::factory()->create();

        $ownerChannel = NotificationChannel::factory()->for($owner)->create(['is_active' => true]);
        $otherChannel = NotificationChannel::factory()->for($other)->create(['is_active' => true]);

        $notified = [];
        $mock = Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyBusinessKpi')
            ->andReturnUsing(function (NotificationChannel $c) use (&$notified) {
                $notified[] = $c->id;
            });
        $this->app->instance(NotificationService::class, $mock);

        $this->notify($this->makeIncident('orphan-monitor.example.com'), $owner->id);

        $this->assertSame([$ownerChannel->id], $notified);
        $this->assertNotContains($otherChannel->id, $notified);
    }
}
