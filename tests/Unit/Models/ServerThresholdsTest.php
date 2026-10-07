<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for Server::thresholds().
 *
 * Verifies that:
 *   - Without settings, the global config/monitoring.php defaults are returned
 *     verbatim with all six expected keys.
 *   - A partial override via settings['thresholds'] replaces only the specified
 *     keys while inheriting the rest from the global config.
 *
 * config('monitoring.server_health') defaults (from config/monitoring.php):
 *   disk_warning=85, disk_critical=92, ram_warning=88, ram_critical=95,
 *   cpu_warning=90, cpu_critical=97, cpu_sustained_points=3
 */
class ServerThresholdsTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────
    // No settings → global config returned unchanged
    // ──────────────────────────────────────────────────────────────────────

    public function test_thresholds_returns_global_config_when_no_settings_override(): void
    {
        // Pin known values so the test is deterministic regardless of .env overrides.
        config()->set('monitoring.server_health', [
            'disk_warning' => 85,
            'disk_critical' => 92,
            'ram_warning' => 88,
            'ram_critical' => 95,
            'cpu_warning' => 90,
            'cpu_critical' => 97,
            'cpu_sustained_points' => 3,
        ]);

        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'settings' => null,
        ]);

        $thresholds = $server->thresholds();

        $this->assertEquals(85, $thresholds['disk_warning']);
        $this->assertEquals(92, $thresholds['disk_critical']);
        $this->assertEquals(88, $thresholds['ram_warning']);
        $this->assertEquals(95, $thresholds['ram_critical']);
        $this->assertEquals(90, $thresholds['cpu_warning']);
        $this->assertEquals(97, $thresholds['cpu_critical']);
        $this->assertEquals(3, $thresholds['cpu_sustained_points']);
    }

    public function test_thresholds_contains_all_expected_keys_when_settings_is_null(): void
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'settings' => null,
        ]);

        $keys = array_keys($server->thresholds());

        foreach (['disk_warning', 'disk_critical', 'ram_warning', 'ram_critical', 'cpu_warning', 'cpu_critical', 'cpu_sustained_points'] as $key) {
            $this->assertContains($key, $keys, "Expected key '{$key}' missing from thresholds().");
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Partial override — overridden key replaces global, rest inherited
    // ──────────────────────────────────────────────────────────────────────

    public function test_settings_threshold_override_replaces_only_the_specified_key(): void
    {
        config()->set('monitoring.server_health', [
            'disk_warning' => 85,
            'disk_critical' => 92,
            'ram_warning' => 88,
            'ram_critical' => 95,
            'cpu_warning' => 90,
            'cpu_critical' => 97,
            'cpu_sustained_points' => 3,
        ]);

        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'settings' => [
                'thresholds' => [
                    'disk_warning' => 95, // override
                ],
            ],
        ]);

        $thresholds = $server->thresholds();

        // Overridden key must equal the custom value.
        $this->assertEquals(95, $thresholds['disk_warning']);

        // Non-overridden keys must still equal the global config defaults.
        $this->assertEquals(92, $thresholds['disk_critical']);
        $this->assertEquals(88, $thresholds['ram_warning']);
        $this->assertEquals(95, $thresholds['ram_critical']);
        $this->assertEquals(90, $thresholds['cpu_warning']);
        $this->assertEquals(97, $thresholds['cpu_critical']);
        $this->assertEquals(3, $thresholds['cpu_sustained_points']);
    }

    public function test_multiple_partial_overrides_all_take_effect(): void
    {
        config()->set('monitoring.server_health', [
            'disk_warning' => 85,
            'disk_critical' => 92,
            'ram_warning' => 88,
            'ram_critical' => 95,
            'cpu_warning' => 90,
            'cpu_critical' => 97,
            'cpu_sustained_points' => 3,
        ]);

        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'settings' => [
                'thresholds' => [
                    'disk_warning' => 90,
                    'disk_critical' => 97,
                    'cpu_warning' => 80,
                ],
            ],
        ]);

        $thresholds = $server->thresholds();

        $this->assertEquals(90, $thresholds['disk_warning']);
        $this->assertEquals(97, $thresholds['disk_critical']);
        $this->assertEquals(80, $thresholds['cpu_warning']);

        // Unmodified keys remain from global config.
        $this->assertEquals(88, $thresholds['ram_warning']);
        $this->assertEquals(95, $thresholds['ram_critical']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // settings array with no thresholds key → same as no settings
    // ──────────────────────────────────────────────────────────────────────

    public function test_settings_without_thresholds_key_falls_back_to_global(): void
    {
        config()->set('monitoring.server_health.disk_warning', 85);

        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'settings' => ['some_other_key' => 'value'],
        ]);

        $thresholds = $server->thresholds();

        $this->assertEquals(85, $thresholds['disk_warning']);
    }
}
