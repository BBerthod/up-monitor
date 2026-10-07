<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteReportSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createAuthenticatedUser(): User
    {
        $team = Team::factory()->create();
        $user = User::factory()->create([
            'team_id' => $team->id,
            'role' => 'member',
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_update_accepts_a_valid_frequency_and_recipients(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->create(['team_id' => $user->team_id]);

        $response = $this->put(route('sites.update', $site->id), [
            'report_frequency' => 'weekly',
            'report_recipients' => ['owner@example.com', 'second@example.com'],
        ]);

        $response->assertRedirect(route('sites.index'));
        $site->refresh();
        $this->assertSame('weekly', $site->report_frequency->value);
        $this->assertSame(['owner@example.com', 'second@example.com'], $site->report_recipients);
    }

    public function test_update_rejects_an_invalid_frequency(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->create(['team_id' => $user->team_id]);

        $response = $this->put(route('sites.update', $site->id), [
            'report_frequency' => 'daily',
        ]);

        $response->assertSessionHasErrors('report_frequency');
    }

    public function test_update_rejects_a_null_frequency_because_the_column_is_not_nullable(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->create(['team_id' => $user->team_id]);

        $response = $this->put(route('sites.update', $site->id), [
            'report_frequency' => null,
        ]);

        $response->assertSessionHasErrors('report_frequency');
    }

    public function test_update_rejects_a_non_email_recipient(): void
    {
        $user = $this->createAuthenticatedUser();
        $site = Site::factory()->create(['team_id' => $user->team_id]);

        $response = $this->put(route('sites.update', $site->id), [
            'report_recipients' => ['not-an-email'],
        ]);

        $response->assertSessionHasErrors('report_recipients.0');
    }
}
