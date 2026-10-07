<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\StatusPage;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicStatusPageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeStatusPage(Team $team, array $monitors = []): StatusPage
    {
        $page = StatusPage::factory()->for($team)->create();
        if ($monitors) {
            $page->monitors()->attach(
                collect($monitors)->mapWithKeys(fn ($m, $i) => [$m->id => ['sort_order' => $i]])->all()
            );
        }

        return $page;
    }

    private function addCheck(Monitor $monitor, CheckStatus $status): MonitorCheck
    {
        return MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => $status,
            'response_time_ms' => 100,
            'status_code' => $status === CheckStatus::UP ? 200 : 500,
            'checked_at' => now()->subMinutes(2),
        ]);
    }

    public function test_overall_status_is_operational_when_all_monitors_up(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a->where('overall_status', 'operational'));
    }

    public function test_overall_status_is_degraded_when_active_incident_exists(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(10),
        ]);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a->where('overall_status', 'degraded'));
    }

    public function test_overall_status_is_major_outage_when_every_monitor_is_down(): void
    {
        $team = Team::factory()->create();
        $monitors = Monitor::factory()->for($team)->count(2)->create();
        $monitors->each(fn ($m) => $this->addCheck($m, CheckStatus::DOWN));
        $page = $this->makeStatusPage($team, $monitors->all());
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a
            ->where('overall_status', 'major_outage')
            ->where('summary', ['total' => 2, 'down' => 2]));
    }

    public function test_overall_status_is_partial_outage_when_some_monitors_are_down(): void
    {
        $team = Team::factory()->create();
        $monitors = Monitor::factory()->for($team)->count(4)->create();
        $this->addCheck($monitors[0], CheckStatus::DOWN);
        $monitors->slice(1)->each(fn ($m) => $this->addCheck($m, CheckStatus::UP));
        $page = $this->makeStatusPage($team, $monitors->all());
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a
            ->where('overall_status', 'partial_outage')
            ->where('summary', ['total' => 4, 'down' => 1])
            ->where('meta.description', fn ($d) => str_contains($d, 'Partial outage: 1 of 4 services down.')));
    }

    public function test_days_without_checks_are_exposed_as_no_data(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a
            ->has('monitors.0.daily_breakdown', 90)
            ->where('monitors.0.daily_breakdown.0.uptime', null)
            ->where('monitors.0.daily_breakdown.0.status', 'no_data')
            ->where('monitors.0.daily_breakdown.89.status', 'up')
            ->where('monitors.0.uptime_30d', 100)
        );
    }

    public function test_past_incidents_are_limited_to_the_last_14_days(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::SSL,
            'started_at' => now()->subDays(3)->subMinutes(42),
            'resolved_at' => now()->subDays(3),
        ]);
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subDays(20),
            'resolved_at' => now()->subDays(20)->addHour(),
        ]);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a
            ->has('pastIncidents', 1)
            ->where('pastIncidents.0.cause_label', IncidentCause::SSL->label())
            ->where('pastIncidents.0.duration_seconds', 42 * 60)
            ->where('past_incident_days', 14)
            ->where('days_since_last_incident', 3)
            ->where('overall_status', 'operational'));
    }

    public function test_props_expose_no_internal_monitor_data(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create([
            'name' => 'https://internal-host.example.com/private/admin?token=abc',
            'url' => 'https://internal-host.example.com/private/admin?token=abc',
        ]);
        MonitorCheck::create([
            'monitor_id' => $monitor->id,
            'status' => CheckStatus::DOWN,
            'response_time_ms' => 100,
            'status_code' => 500,
            'error_message' => 'cURL error 7: raw-internal-error 10.0.0.12',
            'checked_at' => now()->subMinutes(2),
        ]);
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'started_at' => now()->subHour(),
            'notes' => 'secret-internal-note',
        ]);
        $page = $this->makeStatusPage($team, [$monitor]);

        $response = $this->get(route('status.show', $page->slug));

        $response->assertOk();
        $props = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('/private/admin', $props);
        $this->assertStringNotContainsString('token=abc', $props);
        $this->assertStringNotContainsString('raw-internal-error', $props);
        $this->assertStringNotContainsString('10.0.0.12', $props);
        $this->assertStringNotContainsString('secret-internal-note', $props);
        $response->assertInertia(fn ($a) => $a->where('monitors.0.name', 'internal-host.example.com'));
    }

    public function test_page_exposes_seo_meta_and_generation_time(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a
            ->where('meta.canonical', route('status.show', $page->slug))
            ->where('meta.description', fn ($d) => str_contains($d, 'All 1 service operational.'))
            ->has('generated_at'));
    }

    public function test_page_renders_all_monitors_for_a_member_of_another_team(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        $page = $this->makeStatusPage($team, [$monitor]);
        $outsider = \App\Models\User::factory()->for(Team::factory())->create();

        $this->actingAs($outsider)->get(route('status.show', $page->slug))
            ->assertOk()
            ->assertInertia(fn ($a) => $a->has('monitors', 1));
    }

    public function test_measured_days_reflects_actual_check_count(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        foreach ([5, 3, 1] as $daysAgo) {
            MonitorCheck::create([
                'monitor_id' => $monitor->id,
                'status' => CheckStatus::UP,
                'response_time_ms' => 100,
                'status_code' => 200,
                'checked_at' => now()->subDays($daysAgo),
            ]);
        }
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(fn ($a) => $a->where('monitors.0.measured_days', 3));
    }

    public function test_active_incidents_include_cause_label(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->for($team)->create();
        $this->addCheck($monitor, CheckStatus::UP);
        MonitorIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::TIMEOUT,
            'started_at' => now()->subHours(2),
        ]);
        $page = $this->makeStatusPage($team, [$monitor]);
        $response = $this->get(route('status.show', $page->slug));
        $response->assertOk();
        $response->assertInertia(
            fn ($a) => $a->where('activeIncidents.0.cause_label', IncidentCause::TIMEOUT->label())
        );
    }

    public function test_inactive_status_page_returns_404(): void
    {
        $team = Team::factory()->create();
        $page = StatusPage::factory()->inactive()->for($team)->create();
        $this->get(route('status.show', $page->slug))->assertNotFound();
    }
}
