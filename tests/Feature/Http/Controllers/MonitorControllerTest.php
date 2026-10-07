<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\MonitorMethod;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createAuthenticatedUser(): User
    {
        $team = Team::factory()->create();

        return User::factory()->create(['team_id' => $team->id]);
    }

    public function test_guest_cannot_access_monitors(): void
    {
        $response = $this->get(route('monitors.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_monitors_index(): void
    {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->count(3)->for($user->team)->create();

        $response = $this->actingAs($user)->get(route('monitors.index'));

        $response->assertStatus(200);
    }

    public function test_user_only_sees_own_team_monitors(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();

        Monitor::factory()->for($user->team)->create(['name' => 'My Monitor']);
        Monitor::factory()->for($otherTeam)->create(['name' => 'Other Monitor']);

        $response = $this->actingAs($user)->get(route('monitors.index'));

        $response->assertStatus(200);
    }

    public function test_can_create_monitor_with_valid_data(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Test Website',
            'type' => 'http',
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('monitors', [
            'name' => 'Test Website',
            'team_id' => $user->team_id,
        ]);
    }

    public function test_cannot_create_monitor_with_invalid_url(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Bad URL',
            'url' => 'not-a-url',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertSessionHasErrors('url');
        $this->assertDatabaseCount('monitors', 0);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Custom request headers (affiliate /go/ referer guard)
    // ──────────────────────────────────────────────────────────────────────

    public function test_cannot_create_monitor_with_host_request_header(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Affiliate guard',
            'type' => 'http',
            'url' => 'https://example.com/go/B000001',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
            'request_headers' => ['Host' => 'evil.example.com'],
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertSessionHasErrors('request_headers');
        $this->assertDatabaseCount('monitors', 0);
    }

    public function test_can_create_monitor_with_referer_request_header(): void
    {
        $user = $this->createAuthenticatedUser();

        $data = [
            'name' => 'Affiliate guard',
            'type' => 'http',
            'url' => 'https://example.com/go/B000001',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
            'request_headers' => ['Referer' => 'https://example.com/'],
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseHas('monitors', [
            'name' => 'Affiliate guard',
            'team_id' => $user->team_id,
        ]);

        $monitor = Monitor::where('name', 'Affiliate guard')->firstOrFail();
        $this->assertSame(['Referer' => 'https://example.com/'], $monitor->request_headers);
    }

    // ──────────────────────────────────────────────────────────────────────
    // URL normalisation / duplicate detection (audit 2026-09-01, PSI quota)
    // ──────────────────────────────────────────────────────────────────────

    public static function duplicateUrlProvider(): array
    {
        return [
            'trailing slash' => ['https://example.com', 'https://example.com/'],
            'host casing' => ['https://example.com', 'https://Example.com'],
        ];
    }

    #[DataProvider('duplicateUrlProvider')]
    public function test_cannot_create_monitor_that_duplicates_an_existing_one_after_normalization(
        string $existingUrl,
        string $duplicateUrl
    ): void {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => $existingUrl]);

        $data = [
            'name' => 'Duplicate',
            'type' => 'http',
            'url' => $duplicateUrl,
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertSessionHasErrors('url');
        $this->assertDatabaseCount('monitors', 1);
    }

    public function test_can_create_monitor_with_same_normalized_url_on_a_different_team(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        Monitor::factory()->for($otherTeam)->create(['type' => 'http', 'url' => 'https://example.com']);

        $data = [
            'name' => 'Same URL, other team',
            'type' => 'http',
            'url' => 'https://example.com/',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseCount('monitors', 2);
    }

    public function test_can_create_port_monitor_sharing_a_host_with_an_http_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'https://example.com']);

        $data = [
            'name' => 'Same host, port type',
            'type' => 'port',
            'url' => 'example.com',
            'port' => 22,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseCount('monitors', 2);
    }

    /**
     * Scheme (http vs https) and www vs apex are deliberately allowed to
     * coexist: teams run one monitor per variant on purpose to catch a
     * regression on either independently — see
     * tests/Feature/Services/HealthDropDeduplicationTest.php and
     * tests/Feature/Jobs/DispatchInsightsTest.php, which cover the
     * downstream per-site insight de-duplication for this exact pattern.
     */
    public function test_can_create_an_https_monitor_alongside_an_existing_http_one(): void
    {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'http://example.com']);

        $data = [
            'name' => 'HTTPS variant',
            'type' => 'http',
            'url' => 'https://example.com',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseCount('monitors', 2);
    }

    public function test_can_create_a_www_monitor_alongside_an_existing_apex_one(): void
    {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'https://example.com']);

        $data = [
            'name' => 'www variant',
            'type' => 'http',
            'url' => 'https://www.example.com',
            'method' => MonitorMethod::GET->value,
            'expected_status_code' => 200,
            'interval' => 5,
        ];

        $response = $this->actingAs($user)->post(route('monitors.store'), $data);

        $response->assertRedirect();
        $this->assertDatabaseCount('monitors', 2);
    }

    public function test_updating_a_monitor_without_changing_its_url_does_not_self_block(): void
    {
        $user = $this->createAuthenticatedUser();
        $monitor = Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'https://example.com']);

        $response = $this->actingAs($user)->put(route('monitors.update', $monitor), [
            'name' => 'Renamed',
            'url' => 'https://example.com/',
            'method' => $monitor->method->value,
            'expected_status_code' => 200,
            'interval' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'Renamed']);
    }

    public function test_cannot_update_monitor_to_duplicate_another_ones_normalized_url(): void
    {
        $user = $this->createAuthenticatedUser();
        Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'https://example.com']);
        $monitor = Monitor::factory()->for($user->team)->create(['type' => 'http', 'url' => 'https://other.example.com']);

        $response = $this->actingAs($user)->put(route('monitors.update', $monitor), [
            'url' => 'https://example.com/',
            'method' => $monitor->method->value,
            'expected_status_code' => 200,
            'interval' => 1,
        ]);

        $response->assertSessionHasErrors('url');
    }

    public function test_can_update_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        $monitor = Monitor::factory()->for($user->team)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->put(route('monitors.update', $monitor), [
            'name' => 'New Name',
            'url' => $monitor->url,
            'method' => $monitor->method->value,
            'expected_status_code' => 200,
            'interval' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'New Name']);
    }

    public function test_can_delete_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        $monitor = Monitor::factory()->for($user->team)->create();

        $response = $this->actingAs($user)->delete(route('monitors.destroy', $monitor));

        $response->assertRedirect(route('monitors.index'));
        $this->assertDatabaseMissing('monitors', ['id' => $monitor->id]);
    }

    public function test_can_pause_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        $monitor = Monitor::factory()->for($user->team)->create(['is_active' => true]);

        $response = $this->actingAs($user)->post(route('monitors.pause', $monitor));

        $response->assertRedirect();
        $this->assertFalse($monitor->fresh()->is_active);
    }

    public function test_can_resume_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        $monitor = Monitor::factory()->for($user->team)->create(['is_active' => false]);

        $response = $this->actingAs($user)->post(route('monitors.resume', $monitor));

        $response->assertRedirect();
        $this->assertTrue($monitor->fresh()->is_active);
    }

    public function test_cannot_access_other_team_monitor(): void
    {
        $user = $this->createAuthenticatedUser();
        $otherTeam = Team::factory()->create();
        $otherMonitor = Monitor::factory()->for($otherTeam)->create();

        $response = $this->actingAs($user)->get(route('monitors.show', $otherMonitor));

        $response->assertNotFound();
    }
}
