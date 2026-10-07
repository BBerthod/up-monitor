<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_target_is_accepted_persisted_and_returned_by_the_dashboard(): void
    {
        $team = Team::factory()->create(['name' => 'Original team']);
        $user = User::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->put(route('settings.team.update'), [
                'name' => 'This must not be changed by an SLA update',
                'sla_target' => 99.95,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Team updated.');

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => 'Original team',
            'sla_target' => 99.95,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('slaTarget', 99.95));
    }

    #[DataProvider('invalidSlaTargets')]
    public function test_invalid_sla_targets_are_rejected(mixed $slaTarget): void
    {
        $team = Team::factory()->create(['sla_target' => 99.90]);
        $user = User::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user)
            ->from(route('settings.index'))
            ->put(route('settings.team.update'), ['sla_target' => $slaTarget])
            ->assertRedirect(route('settings.index'))
            ->assertSessionHasErrors('sla_target');

        $this->assertSame('99.90', $team->fresh()->sla_target);
    }

    public static function invalidSlaTargets(): array
    {
        return [
            'below minimum' => [89],
            'above maximum' => [101],
            'more than two decimals' => [99.999],
            'not numeric' => ['abc'],
        ];
    }

    public function test_user_cannot_update_another_teams_sla_target(): void
    {
        $otherTeam = Team::factory()->create(['sla_target' => 99.90]);
        $usersTeam = Team::factory()->create(['sla_target' => 98.00]);
        $user = User::factory()->create(['team_id' => $usersTeam->id]);

        $this->actingAs($user)
            ->put(route('settings.team.update'), [
                'team_id' => $otherTeam->id,
                'sla_target' => 99.95,
            ])
            ->assertRedirect();

        $this->assertSame('99.90', $otherTeam->fresh()->sla_target);
        $this->assertSame('99.95', $usersTeam->fresh()->sla_target);
    }
}
