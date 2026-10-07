<?php

namespace Tests\Feature\Policies;

use App\Models\IngestSource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestSourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(?Team $team = null): User
    {
        $team ??= Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    private function createSource(?Team $team = null): IngestSource
    {
        $team ??= Team::factory()->create();

        return IngestSource::factory()->for($team)->create();
    }

    // ──────────────────────────────────────────────────
    // Same team — allowed
    // ──────────────────────────────────────────────────

    public function test_user_can_view_own_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource($user->team);

        $this->assertTrue($user->can('view', $source));
    }

    public function test_user_can_update_own_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource($user->team);

        $this->assertTrue($user->can('update', $source));
    }

    public function test_user_can_delete_own_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource($user->team);

        $this->assertTrue($user->can('delete', $source));
    }

    // ──────────────────────────────────────────────────
    // Different team — denied
    // ──────────────────────────────────────────────────

    public function test_user_cannot_view_other_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource();

        $this->assertFalse($user->can('view', $source));
    }

    public function test_user_cannot_update_other_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource();

        $this->assertFalse($user->can('update', $source));
    }

    public function test_user_cannot_delete_other_team_ingest_source(): void
    {
        $user = $this->createUser();
        $source = $this->createSource();

        $this->assertFalse($user->can('delete', $source));
    }
}
