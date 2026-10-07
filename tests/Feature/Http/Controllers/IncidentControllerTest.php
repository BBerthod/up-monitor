<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncidentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_carries_a_human_readable_cause_label_for_every_incident(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        $monitor = Monitor::factory()->for($team)->create();

        // Multi-word cause: a plain str_replace('_', ' ', ...) only swaps the
        // first underscore and used to render "failed smoke_test".
        MonitorIncident::factory()->resolved()->create([
            'monitor_id' => $monitor->id,
            'cause' => IncidentCause::FAILED_SMOKE_TEST,
        ]);

        $this->actingAs($user)
            ->get(route('incidents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incidents/Index')
                ->where('incidents.data.0.cause_label', 'Post-deploy smoke test failed')
            );
    }
}
