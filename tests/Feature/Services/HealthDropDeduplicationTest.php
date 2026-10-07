<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Enums\KpiSource;
use App\Models\Insight;
use App\Models\KpiSnapshot;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\Team;
use App\Services\HealthDropDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies HealthDropDetector deduplicates HEALTH_DROP insights by SITE, not by
 * monitor (Fix 2). Multiple monitors sharing a hostname (http + https, www +
 * non-www) must yield exactly one insight — this is the fix for the triple-email
 * bug where webcompare.com fired three identical "Health F" alerts.
 *
 * siteFromMonitor() strips www. but not the scheme, so http://example.com,
 * https://example.com and http://www.example.com all resolve to "example.com".
 *
 * Companion file (purge behaviour): HealthDropPurgeTest.
 */
class HealthDropDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(): Team
    {
        return Team::factory()->create();
    }

    private function makeSite(Team $team, string $domain = 'example.com'): Site
    {
        return Site::factory()->create([
            'team_id' => $team->id,
            'primary_domain' => $domain,
            'is_active' => true,
        ]);
    }

    private function makeMonitor(Team $team, Site $site, string $url): Monitor
    {
        return Monitor::factory()->create([
            'team_id' => $team->id,
            'site_id' => $site->id,
            'url' => $url,
        ]);
    }

    private function seedHealthSnapshots(
        string $site,
        float $prevScore,
        string $prevGrade,
        float $curScore,
        string $curGrade,
    ): void {
        KpiSnapshot::factory()->create([
            'site' => $site,
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => $prevScore,
            'period_days' => 1,
            'captured_at' => now()->subDay(),
            'meta' => ['grade' => $prevGrade],
        ]);
        KpiSnapshot::factory()->create([
            'site' => $site,
            'source' => KpiSource::CUSTOM->value,
            'metric' => 'health_score',
            'value' => $curScore,
            'period_days' => 1,
            'captured_at' => now(),
            'meta' => ['grade' => $curGrade],
        ]);
    }

    private function countOpenHealthDrops(string $site): int
    {
        return Insight::withoutGlobalScopes()
            ->where('site', $site)
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->count();
    }

    /** D→F drop on a single monitor must produce exactly one CRITICAL insight. */
    public function test_single_monitor_grade_drop_d_to_f_creates_critical_insight(): void
    {
        $team = $this->makeTeam();
        $site = $this->makeSite($team);
        $monitor = $this->makeMonitor($team, $site, 'https://example.com');

        $this->seedHealthSnapshots('example.com', 64, 'D', 55, 'F');

        $count = app(HealthDropDetector::class)->detectForMonitor($monitor);

        $this->assertSame(1, $count);

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HEALTH_DROP->value)
            ->whereNull('acknowledged_at')
            ->first();

        $this->assertNotNull($insight);
        $this->assertSame(InsightSeverity::CRITICAL->value, $insight->severity->value);
        $this->assertSame('D', $insight->payload['previous_grade']);
        $this->assertSame('F', $insight->payload['current_grade']);
        $this->assertSame($team->id, $insight->team_id);
    }

    /** HTTP + HTTPS monitors on the same hostname must yield exactly one insight. */
    public function test_two_monitors_same_site_http_and_https_produce_exactly_one_insight(): void
    {
        $team = $this->makeTeam();
        $site = $this->makeSite($team);
        $monitor1 = $this->makeMonitor($team, $site, 'http://example.com');
        $monitor2 = $this->makeMonitor($team, $site, 'https://example.com');

        $this->seedHealthSnapshots('example.com', 82, 'B', 55, 'F');

        $detector = app(HealthDropDetector::class);
        $detector->detectForMonitor($monitor1);
        $detector->detectForMonitor($monitor2);

        $this->assertSame(1, $this->countOpenHealthDrops('example.com'));
    }

    /** www + non-www monitors on the same hostname must yield exactly one insight. */
    public function test_www_and_non_www_monitors_produce_exactly_one_insight(): void
    {
        $team = $this->makeTeam();
        $site = $this->makeSite($team);
        $monitor1 = $this->makeMonitor($team, $site, 'http://www.example.com');
        $monitor2 = $this->makeMonitor($team, $site, 'https://example.com');

        $this->seedHealthSnapshots('example.com', 75, 'C', 55, 'F');

        $detector = app(HealthDropDetector::class);
        $detector->detectForMonitor($monitor1);
        $detector->detectForMonitor($monitor2);

        $this->assertSame(1, $this->countOpenHealthDrops('example.com'));
    }
}
