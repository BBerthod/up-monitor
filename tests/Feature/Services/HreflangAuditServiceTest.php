<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\HreflangAuditService;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for HreflangAuditService::detectForSite().
 *
 * The case this exists for: a site served hreflang tags that pointed only at
 * themselves — every locale declared itself and named no siblings. Google
 * treats a one-way annotation as none, so the locales competed instead of
 * clustering. Found by hand, months later.
 */
class HreflangAuditServiceTest extends TestCase
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

    private function makeSite(array $locales = ['fr', 'de']): Site
    {
        return Site::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'primary_domain' => 'fr.example.com',
            'domains' => ['{locale}.example.com'],
            'locales' => $locales,
            'primary_locale' => $locales[0] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * Build a page whose <head> carries the given hreflang annotations.
     *
     * @param  array<string, string>  $annotations  hreflang => href
     */
    private function page(array $annotations): string
    {
        $links = '';

        foreach ($annotations as $lang => $href) {
            $links .= '<link rel="alternate" hreflang="'.$lang.'" href="'.$href.'" />';
        }

        return '<html><head>'.$links.'</head><body>x</body></html>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. The production case: every locale names only itself
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_self_only_annotations(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://fr.example.com/' => Http::response($this->page([
                'fr' => 'https://fr.example.com/',
            ]), 200),
            'https://de.example.com/' => Http::response($this->page([
                'de' => 'https://de.example.com/',
            ]), 200),
        ]);

        $this->assertSame(1, (new HreflangAuditService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::HREFLANG_BROKEN->value)
            ->first();

        $this->assertNotNull($insight);
        // The cluster does not exist at all — systemic.
        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('self_only', $insight->payload['problems'][0]['type']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. A correct reciprocal cluster is silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_reciprocal_annotations_create_no_insight(): void
    {
        $site = $this->makeSite();

        $annotations = [
            'fr' => 'https://fr.example.com/',
            'de' => 'https://de.example.com/',
        ];

        Http::fake([
            'https://fr.example.com/' => Http::response($this->page($annotations), 200),
            'https://de.example.com/' => Http::response($this->page($annotations), 200),
        ]);

        $this->assertSame(0, (new HreflangAuditService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. A one-way link degrades rather than disables — WARNING
    // ──────────────────────────────────────────────────────────────────────

    public function test_non_reciprocal_link_is_a_warning(): void
    {
        $site = $this->makeSite(['fr', 'de', 'it']);

        Http::fake([
            // fr names all three...
            'https://fr.example.com/' => Http::response($this->page([
                'fr' => 'https://fr.example.com/',
                'de' => 'https://de.example.com/',
                'it' => 'https://it.example.com/',
            ]), 200),
            // ...de names fr back...
            'https://de.example.com/' => Http::response($this->page([
                'de' => 'https://de.example.com/',
                'fr' => 'https://fr.example.com/',
            ]), 200),
            // ...but it names only de, never fr.
            'https://it.example.com/' => Http::response($this->page([
                'it' => 'https://it.example.com/',
                'de' => 'https://de.example.com/',
            ]), 200),
        ]);

        $this->assertSame(1, (new HreflangAuditService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame('non_reciprocal', $insight->payload['problems'][0]['type']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. No annotations at all
    // ──────────────────────────────────────────────────────────────────────

    public function test_missing_annotations_are_critical(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://*.example.com/' => Http::response('<html><head></head><body>x</body></html>', 200),
        ]);

        $this->assertSame(1, (new HreflangAuditService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('missing', $insight->payload['problems'][0]['type']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. x-default must not make a self-only page look annotated
    // ──────────────────────────────────────────────────────────────────────

    public function test_x_default_does_not_count_as_a_sibling(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://fr.example.com/' => Http::response($this->page([
                'fr' => 'https://fr.example.com/',
                'x-default' => 'https://fr.example.com/',
            ]), 200),
            'https://de.example.com/' => Http::response($this->page([
                'de' => 'https://de.example.com/',
                'x-default' => 'https://fr.example.com/',
            ]), 200),
        ]);

        // Two annotations each, but only one real locale — still self-only.
        $this->assertSame(1, (new HreflangAuditService)->detectForSite($site));
        $this->assertSame(
            'self_only',
            Insight::withoutGlobalScopes()->first()->payload['problems'][0]['type'],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Cosmetic URL differences are not reciprocity failures
    // ──────────────────────────────────────────────────────────────────────

    public function test_www_and_trailing_slash_differences_are_tolerated(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://fr.example.com/' => Http::response($this->page([
                'fr' => 'https://fr.example.com',
                'de' => 'https://www.de.example.com/',
            ]), 200),
            'https://de.example.com/' => Http::response($this->page([
                'de' => 'https://de.example.com/',
                'fr' => 'https://www.fr.example.com',
            ]), 200),
        ]);

        // Same pages, written differently — comparing on host alone keeps this
        // from reporting a failure that does not exist.
        $this->assertSame(0, (new HreflangAuditService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. Single-locale sites are out of scope
    // ──────────────────────────────────────────────────────────────────────

    public function test_single_locale_site_is_skipped(): void
    {
        $site = $this->makeSite(['fr']);
        Http::fake();

        $this->assertSame(0, (new HreflangAuditService)->detectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. An unreachable locale is an availability problem, not an hreflang one
    // ──────────────────────────────────────────────────────────────────────

    public function test_unreachable_locale_does_not_produce_a_false_positive(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'https://fr.example.com/' => Http::response($this->page([
                'fr' => 'https://fr.example.com/',
                'de' => 'https://de.example.com/',
            ]), 200),
            'https://de.example.com/' => Http::response('down', 503),
        ]);

        // Only one locale observed — not enough to judge reciprocity.
        $this->assertSame(0, (new HreflangAuditService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. Attribute order must not matter
    // ──────────────────────────────────────────────────────────────────────

    public function test_hreflang_before_rel_is_still_matched(): void
    {
        $site = $this->makeSite();

        $reversed = '<link hreflang="fr" rel="alternate" href="https://fr.example.com/" />'
            .'<link hreflang="de" rel="alternate" href="https://de.example.com/" />';

        Http::fake([
            'https://*.example.com/' => Http::response(
                '<html><head>'.$reversed.'</head><body>x</body></html>',
                200,
            ),
        ]);

        // Templates emit both orders; a fixed-order regex would miss half of them.
        $this->assertSame(0, (new HreflangAuditService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 10. Idempotence
    // ──────────────────────────────────────────────────────────────────────

    public function test_previous_insight_is_cleared_once_fixed(): void
    {
        $site = $this->makeSite();

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::HREFLANG_BROKEN->value,
            'severity' => InsightSeverity::CRITICAL->value,
            'title' => 'stale finding',
            'payload' => [],
            'impact_score' => 100,
            'detected_at' => now()->subDay(),
        ]);

        $annotations = [
            'fr' => 'https://fr.example.com/',
            'de' => 'https://de.example.com/',
        ];

        Http::fake([
            'https://*.example.com/' => Http::response($this->page($annotations), 200),
        ]);

        (new HreflangAuditService)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::HREFLANG_BROKEN->value,
        ]);
    }
}
