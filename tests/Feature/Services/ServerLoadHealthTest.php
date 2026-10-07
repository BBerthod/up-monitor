<?php

namespace Tests\Feature\Services;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Services\ServerHealthDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ServerHealthDetector::evaluate() — load_avg_5 dimension only.
 *
 * evaluateLoad() requires:
 *   1. load_avg_5 non-null in the metric.
 *   2. server.settings.thresholds.load_cores > 0  (cores unknown = no alert).
 *   3. ratio = load_avg_5 / cores compared against load_warning (0.85) and
 *      load_critical (1.0) from config/monitoring.php.
 *
 * Anti-false-positive: prod-server at load 13 / 64 cores = ratio 0.20 → silent.
 *
 * Assertions target payload.metric='load' to isolate load from disk/ram/cpu,
 * since all four evaluators run on every evaluate() call.
 */
class ServerLoadHealthTest extends TestCase
{
    use RefreshDatabase;

    private ServerHealthDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(ServerHealthDetector::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Create a Server with the given settings (null = no thresholds configured).
     */
    private function makeServer(?array $settings, ?Team $team = null): Server
    {
        $team ??= Team::factory()->create();

        return Server::factory()->create([
            'team_id' => $team->id,
            'settings' => $settings,
        ]);
    }

    /**
     * Create a ServerMetric with low disk/ram/cpu so only load is interesting.
     */
    private function makeMetric(Server $server, ?float $loadAvg5): ServerMetric
    {
        return ServerMetric::factory()->create([
            'server_id' => $server->id,
            'load_avg_5' => $loadAvg5,
            'cpu_percent' => 10.0,
            'ram_percent' => 10.0,
            'disk_percent' => 10.0,
            'captured_at' => now(),
        ]);
    }

    /**
     * Count SERVER_HEALTH insights for the given server whose payload.metric='load'.
     */
    private function loadInsightCount(Server $server): int
    {
        return Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('server_id', $server->id)
            ->get()
            ->filter(fn ($i) => ($i->payload['metric'] ?? '') === 'load')
            ->count();
    }

    // ── Guard — cores not configured ─────────────────────────────────────

    public function test_no_load_insight_when_cores_not_configured(): void
    {
        $server = $this->makeServer(null);
        $metric = $this->makeMetric($server, 50.0);

        $this->detector->evaluate($server, $metric);

        $this->assertSame(0, $this->loadInsightCount($server));
    }

    // ── Anti-false-positive — prod-server: 64 cores, load 13 → healthy ─

    public function test_no_load_insight_for_hetzner_64_cores_load_13(): void
    {
        // Ratio = 13 / 64 ≈ 0.20 — well below load_warning=0.85.
        $server = $this->makeServer(['thresholds' => ['load_cores' => 64]]);
        $metric = $this->makeMetric($server, 13.0);

        $this->detector->evaluate($server, $metric);

        $this->assertSame(0, $this->loadInsightCount($server));
    }

    // ── WARNING — 4 cores, load 3.6 (ratio 0.90 ≥ 0.85) ─────────────────

    public function test_creates_warning_load_insight_when_ratio_above_warning_threshold(): void
    {
        $server = $this->makeServer(['thresholds' => ['load_cores' => 4]]);
        $metric = $this->makeMetric($server, 3.6);

        $this->detector->evaluate($server, $metric);

        $this->assertSame(1, $this->loadInsightCount($server));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('server_id', $server->id)
            ->get()
            ->firstWhere(fn ($i) => ($i->payload['metric'] ?? '') === 'load');

        $this->assertNotNull($insight);
        $this->assertEquals('warning', $insight->severity->value);
    }

    // ── CRITICAL — 4 cores, load 4.5 (ratio 1.125 ≥ 1.0) ────────────────

    public function test_creates_critical_load_insight_when_ratio_above_critical_threshold(): void
    {
        $server = $this->makeServer(['thresholds' => ['load_cores' => 4]]);
        $metric = $this->makeMetric($server, 4.5);

        $this->detector->evaluate($server, $metric);

        $this->assertSame(1, $this->loadInsightCount($server));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::SERVER_HEALTH->value)
            ->where('server_id', $server->id)
            ->get()
            ->firstWhere(fn ($i) => ($i->payload['metric'] ?? '') === 'load');

        $this->assertNotNull($insight);
        $this->assertEquals('critical', $insight->severity->value);
    }

    // ── Guard — null load_avg_5 ───────────────────────────────────────────

    public function test_no_load_insight_when_load_avg_5_is_null(): void
    {
        $server = $this->makeServer(['thresholds' => ['load_cores' => 4]]);
        $metric = $this->makeMetric($server, null);

        $this->detector->evaluate($server, $metric);

        $this->assertSame(0, $this->loadInsightCount($server));
    }
}
