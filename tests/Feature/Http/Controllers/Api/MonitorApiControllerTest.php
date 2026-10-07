<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitorApiControllerTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────

    private function createUserWithTeam(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    // ──────────────────────────────────────────────────
    // index — team isolation
    // ──────────────────────────────────────────────────

    public function test_index_only_returns_current_team_monitors(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        Monitor::factory()->count(3)->for($userA->team)->create();
        Monitor::factory()->count(2)->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.monitors.index'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson(route('api.monitors.index'));

        $response->assertUnauthorized();
    }

    // ──────────────────────────────────────────────────
    // show — cross-team 403
    // ──────────────────────────────────────────────────

    public function test_show_returns_monitor_for_own_team(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.monitors.show', $monitor));

        $response->assertOk();
        $response->assertJsonPath('data.id', $monitor->id);
        $response->assertJsonPath('data.type', 'http');
    }

    public function test_show_exposes_the_monitor_type_for_each_type(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $monitors = [
            'ping' => Monitor::factory()->ping()->for($user->team)->create(),
            'port' => Monitor::factory()->port()->for($user->team)->create(),
            'dns' => Monitor::factory()->dns()->for($user->team)->create(),
        ];

        foreach ($monitors as $expectedType => $monitor) {
            $this->getJson(route('api.monitors.show', $monitor))
                ->assertOk()
                ->assertJsonPath('data.type', $expectedType);
        }
    }

    public function test_show_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.monitors.show', $monitorB));

        // TeamScope global scope filters out monitors from other teams before policy runs,
        // so route-model binding returns 404 rather than policy returning 403.
        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────
    // store
    // ──────────────────────────────────────────────────

    public function test_store_creates_monitor_for_authenticated_team(): void
    {
        $user = $this->createUserWithTeam();

        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.monitors.store'), [
            'name' => 'My API Monitor',
            'url' => 'https://example.com',
            'type' => 'http',
            'method' => 'GET',
            'expected_status_code' => 200,
            'interval' => 5,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'http');
        $this->assertDatabaseHas('monitors', [
            'team_id' => $user->team_id,
            'name' => 'My API Monitor',
        ]);
    }

    // ──────────────────────────────────────────────────
    // update — cross-team 403
    // ──────────────────────────────────────────────────

    public function test_update_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->patchJson(route('api.monitors.update', $monitorB), [
            'name' => 'Hacked',
        ]);

        // TeamScope prevents cross-team monitors from being found; route-model binding returns 404.
        $response->assertNotFound();
    }

    public function test_update_succeeds_for_own_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create(['name' => 'Original']);

        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.monitors.update', $monitor), [
            'name' => 'Updated',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'Updated']);
    }

    // ──────────────────────────────────────────────────
    // destroy — cross-team 403
    // ──────────────────────────────────────────────────

    public function test_destroy_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->deleteJson(route('api.monitors.destroy', $monitorB));

        // TeamScope prevents cross-team monitors from being found; route-model binding returns 404.
        $response->assertNotFound();
        $this->assertDatabaseHas('monitors', ['id' => $monitorB->id]);
    }

    public function test_destroy_deletes_own_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->deleteJson(route('api.monitors.destroy', $monitor));

        $response->assertNoContent();
        $this->assertDatabaseMissing('monitors', ['id' => $monitor->id]);
    }

    // ──────────────────────────────────────────────────
    // pause / resume — cross-team 403
    // ──────────────────────────────────────────────────

    public function test_pause_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->for($userB->team)->create(['is_active' => true]);

        Sanctum::actingAs($userA);

        $response = $this->postJson(route('api.monitors.pause', $monitorB));

        // TeamScope prevents cross-team monitors from being found; route-model binding returns 404.
        $response->assertNotFound();
    }

    public function test_resume_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->inactive()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->postJson(route('api.monitors.resume', $monitorB));

        // TeamScope prevents cross-team monitors from being found; route-model binding returns 404.
        $response->assertNotFound();
    }

    public function test_pause_resume_round_trip_for_own_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create(['is_active' => true]);

        Sanctum::actingAs($user);

        $this->postJson(route('api.monitors.pause', $monitor))->assertOk();
        $monitor->refresh();
        $this->assertFalse($monitor->is_active);

        $this->postJson(route('api.monitors.resume', $monitor))->assertOk();
        $monitor->refresh();
        $this->assertTrue($monitor->is_active);
    }

    // ──────────────────────────────────────────────────
    // checks endpoint — cross-team 403
    // ──────────────────────────────────────────────────

    public function test_checks_endpoint_returns_404_for_other_team_monitor(): void
    {
        $userA = $this->createUserWithTeam();
        $userB = $this->createUserWithTeam();

        $monitorB = Monitor::factory()->for($userB->team)->create();

        Sanctum::actingAs($userA);

        $response = $this->getJson(route('api.monitors.checks', $monitorB));

        // TeamScope prevents cross-team monitors from being found; route-model binding returns 404.
        $response->assertNotFound();
    }

    public function test_checks_endpoint_returns_paginated_checks_for_own_monitor(): void
    {
        $user = $this->createUserWithTeam();
        $monitor = Monitor::factory()->for($user->team)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.monitors.checks', $monitor));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'meta']);
    }
}
