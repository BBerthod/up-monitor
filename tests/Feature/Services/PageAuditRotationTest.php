<?php

namespace Tests\Feature\Services;

use App\Models\Monitor;
use App\Models\PageAuditState;
use App\Models\PageMetric;
use App\Models\Team;
use App\Services\BrokenPageService;
use App\Services\KpiCollector;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests the rotation cursor that stops capped audits from re-probing the same
 * head pages forever.
 *
 * The failure this fixes: BrokenPageService and AffiliateAuditService both cap
 * at 30 pages and both ordered by traffic, so on a site with hundreds of earning
 * pages the tail was never audited. One site had ~40% of its product pages
 * rendering empty placeholders and the audit could not have found it.
 */
class PageAuditRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        UrlSafetyValidator::setResolver(function (string $host, int $type): array {
            if ($type === DNS_A) {
                return [['ip' => '8.8.8.8']];
            }

            return [];
        });
    }

    protected function tearDown(): void
    {
        UrlSafetyValidator::setResolver(null);
        parent::tearDown();
    }

    private function makeService(): BrokenPageService
    {
        return new BrokenPageService(app(KpiCollector::class));
    }

    /**
     * Create $count pages with descending clicks so page-1 is the head page.
     */
    private function seedPages(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            PageMetric::factory()->create([
                'site' => 'example.com',
                'page' => "https://example.com/page-{$i}",
                'clicks' => 1000 - $i,
                'impressions' => 5000,
                'ctr' => 1.0,
                'position' => 5.0,
                'captured_at' => now(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Selection order: never-audited pages come first
    // ──────────────────────────────────────────────────────────────────────

    public function test_never_audited_pages_are_selected_first(): void
    {
        PageAuditState::create([
            'site' => 'example.com',
            'page' => 'https://example.com/old',
            'audit_type' => PageAuditState::TYPE_BROKEN_PAGE,
            'last_audited_at' => now()->subDay(),
        ]);

        $due = PageAuditState::selectDue(
            'example.com',
            PageAuditState::TYPE_BROKEN_PAGE,
            ['https://example.com/old', 'https://example.com/fresh'],
            1,
        );

        // "fresh" has no state row at all → treated as never audited → wins.
        $this->assertSame(['https://example.com/fresh'], $due);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Oldest audit wins among already-audited pages
    // ──────────────────────────────────────────────────────────────────────

    public function test_least_recently_audited_page_is_selected_first(): void
    {
        foreach ([['a', 1], ['b', 5], ['c', 3]] as [$slug, $daysAgo]) {
            PageAuditState::create([
                'site' => 'example.com',
                'page' => "https://example.com/{$slug}",
                'audit_type' => PageAuditState::TYPE_BROKEN_PAGE,
                'last_audited_at' => now()->subDays($daysAgo),
            ]);
        }

        $due = PageAuditState::selectDue(
            'example.com',
            PageAuditState::TYPE_BROKEN_PAGE,
            ['https://example.com/a', 'https://example.com/b', 'https://example.com/c'],
            2,
        );

        $this->assertSame([
            'https://example.com/b', // 5 days — oldest
            'https://example.com/c', // 3 days
        ], $due);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Audit types rotate independently
    // ──────────────────────────────────────────────────────────────────────

    public function test_rotation_is_independent_per_audit_type(): void
    {
        PageAuditState::markAudited(
            'example.com',
            PageAuditState::TYPE_BROKEN_PAGE,
            ['https://example.com/a'],
        );

        // Audited for broken-page, but untouched for affiliate → still due there.
        $due = PageAuditState::selectDue(
            'example.com',
            PageAuditState::TYPE_AFFILIATE,
            ['https://example.com/a', 'https://example.com/b'],
            1,
        );

        $this->assertSame(['https://example.com/a'], $due);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. The real bug: successive runs cover the whole catalogue
    // ──────────────────────────────────────────────────────────────────────

    public function test_successive_runs_cover_the_whole_catalogue(): void
    {
        config(['monitoring.broken_pages.max_pages_per_monitor' => 2]);

        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $this->seedPages(6);

        Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

        // Three runs of two pages each must touch all six, with no repeats —
        // the old head-page ordering would have probed page-1 and page-2 thrice.
        for ($run = 0; $run < 3; $run++) {
            $this->makeService()->detectForMonitor($monitor);
        }

        $audited = PageAuditState::where('site', 'example.com')
            ->where('audit_type', PageAuditState::TYPE_BROKEN_PAGE)
            ->pluck('page')
            ->sort()
            ->values()
            ->all();

        $this->assertCount(6, $audited);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. Probing a page stamps it
    // ──────────────────────────────────────────────────────────────────────

    public function test_probing_a_page_advances_its_cursor(): void
    {
        $team = Team::factory()->create();
        $monitor = Monitor::factory()->create([
            'url' => 'https://example.com',
            'team_id' => $team->id,
        ]);

        $this->seedPages(1);

        Http::fake(['https://example.com/*' => Http::response('ok', 200)]);

        $this->makeService()->detectForMonitor($monitor);

        $this->assertDatabaseHas('page_audit_states', [
            'site' => 'example.com',
            'page' => 'https://example.com/page-1',
            'audit_type' => PageAuditState::TYPE_BROKEN_PAGE,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. markAudited is idempotent — re-stamping updates, never duplicates
    // ──────────────────────────────────────────────────────────────────────

    public function test_mark_audited_upserts_rather_than_duplicating(): void
    {
        PageAuditState::markAudited('example.com', PageAuditState::TYPE_BROKEN_PAGE, ['https://example.com/a']);
        $first = PageAuditState::where('page', 'https://example.com/a')->first()->last_audited_at;

        $this->travel(1)->hours();

        PageAuditState::markAudited('example.com', PageAuditState::TYPE_BROKEN_PAGE, ['https://example.com/a']);

        $this->assertSame(1, PageAuditState::where('page', 'https://example.com/a')->count());
        $this->assertTrue(
            PageAuditState::where('page', 'https://example.com/a')->first()->last_audited_at->greaterThan($first),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Ordering is deterministic, so identical data yields identical picks
    // ──────────────────────────────────────────────────────────────────────

    public function test_selection_is_deterministic_for_untouched_pages(): void
    {
        $pages = ['https://example.com/c', 'https://example.com/a', 'https://example.com/b'];

        $first = PageAuditState::selectDue('example.com', PageAuditState::TYPE_BROKEN_PAGE, $pages, 2);
        $second = PageAuditState::selectDue('example.com', PageAuditState::TYPE_BROKEN_PAGE, $pages, 2);

        $this->assertSame($first, $second);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Empty input and zero limits are handled without querying
    // ──────────────────────────────────────────────────────────────────────

    public function test_empty_candidates_or_zero_limit_returns_nothing(): void
    {
        $this->assertSame([], PageAuditState::selectDue('example.com', PageAuditState::TYPE_BROKEN_PAGE, [], 10));
        $this->assertSame([], PageAuditState::selectDue('example.com', PageAuditState::TYPE_BROKEN_PAGE, ['https://example.com/a'], 0));
    }
}
