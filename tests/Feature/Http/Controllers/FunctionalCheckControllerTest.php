<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\FunctionalCheck;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FunctionalCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Monitor $monitor;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['team_id' => $this->team->id]);
        $this->monitor = Monitor::factory()->for($this->team)->create();
    }

    public function test_store_creates_functional_check(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->user)
            ->post(
                route('monitors.functional-checks.store', $this->monitor),
                [
                    'name' => 'Page Best Deals',
                    'url' => '/best-deals',
                    'type' => 'content',
                    'rules' => [['type' => 'text_present', 'value' => 'Sale']],
                    'check_interval' => 60,
                ]
            );

        $response->assertRedirect();
        $this->assertDatabaseHas('functional_checks', [
            'monitor_id' => $this->monitor->id,
            'name' => 'Page Best Deals',
        ]);
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('monitors.functional-checks.store', $this->monitor), [
                'name' => 'Test',
                'url' => '/',
                'type' => 'content',
                'rules' => [],
            ]);

        $response->assertRedirect(route('login'));
    }

    public function test_store_rejects_other_team_monitor(): void
    {
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create(['team_id' => $otherTeam->id]);
        $otherMonitor = Monitor::factory()->for($otherTeam)->create();

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->user)
            ->post(
                route('monitors.functional-checks.store', $otherMonitor),
                [
                    'name' => 'Test',
                    'url' => '/',
                    'type' => 'content',
                    'rules' => [],
                ]
            );

        $response->assertNotFound();
    }

    public function test_destroy_deletes_functional_check(): void
    {
        $check = FunctionalCheck::factory()->create(['monitor_id' => $this->monitor->id]);

        $response = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->user)
            ->delete(route('monitors.functional-checks.destroy', [$this->monitor, $check]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('functional_checks', ['id' => $check->id]);
    }

    public function test_run_now_dispatches_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $check = FunctionalCheck::factory()->create(['monitor_id' => $this->monitor->id]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->actingAs($this->user)
            ->post(route('monitors.functional-checks.run-now', [$this->monitor, $check]));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunFunctionalCheck::class);
    }
}
