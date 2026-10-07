<?php

namespace Tests\Feature\Http\Controllers\Api;

use App\Jobs\DispatchKpiCollection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KpiCollectionApiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/kpi/collect')->assertUnauthorized();
    }

    public function test_dispatches_kpi_collection(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $user = User::factory()->create(['team_id' => $team->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/kpi/collect')
            ->assertStatus(202)
            ->assertJson(['dispatched' => true]);

        Queue::assertPushed(DispatchKpiCollection::class);
    }
}
