<?php

namespace Tests\Feature\Services;

use App\Enums\InsightSeverity;
use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\Site;
use App\Models\Team;
use App\Services\WordPressVersionService;
use App\Support\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for WordPressVersionService::detectForSite().
 *
 * The gap it closes: most of this fleet is WordPress and nothing in Up ever
 * knew which version any of it ran. Updates were tracked by hand and drifted
 * for months, with no signal anywhere that a site had fallen behind.
 */
class WordPressVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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

    private function makeSite(string $type = 'wordpress'): Site
    {
        return Site::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'primary_domain' => 'example.com',
            'domains' => ['example.com'],
            'type' => $type,
            'is_active' => true,
        ]);
    }

    /**
     * Stub the WordPress.org release feed.
     *
     * @param  list<string>  $insecure
     */
    private function fakeReleases(string $latest = '7.0.2', array $insecure = ['6.4.1']): array
    {
        $offers = [['current' => $latest, 'response' => 'upgrade']];

        foreach ($insecure as $version) {
            $offers[] = ['current' => $version, 'response' => 'insecure'];
        }

        return ['offers' => $offers];
    }

    private function pageWithGenerator(string $version): string
    {
        return '<html><head><meta name="generator" content="WordPress '.$version.'" /></head><body>x</body></html>';
    }

    // ──────────────────────────────────────────────────────────────────────
    // 1. Behind the latest release
    // ──────────────────────────────────────────────────────────────────────

    public function test_detects_outdated_core(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('6.9.4'), 200),
        ]);

        $this->assertSame(1, (new WordPressVersionService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()
            ->where('type', InsightType::OUTDATED_CMS->value)
            ->first();

        $this->assertNotNull($insight);
        $this->assertEquals(InsightSeverity::WARNING, $insight->severity);
        $this->assertSame('6.9.4', $insight->payload['installed_version']);
        $this->assertSame('7.0.2', $insight->payload['latest_version']);
        $this->assertSame('outdated', $insight->payload['status']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 2. Flagged insecure upstream — severity comes from WordPress.org, not
    //    from counting version numbers
    // ──────────────────────────────────────────────────────────────────────

    public function test_insecure_release_is_critical(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(insecure: ['6.4.1']), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('6.4.1'), 200),
        ]);

        $this->assertSame(1, (new WordPressVersionService)->detectForSite($site));

        $insight = Insight::withoutGlobalScopes()->first();

        $this->assertEquals(InsightSeverity::CRITICAL, $insight->severity);
        $this->assertSame('insecure', $insight->payload['status']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 3. Current core is silent
    // ──────────────────────────────────────────────────────────────────────

    public function test_current_core_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('7.0.2'), 200),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    public function test_version_ahead_of_upstream_is_treated_as_current(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(latest: '7.0.2'), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('7.1.0'), 200),
        ]);

        // A site running a release newer than the cached feed is not outdated.
        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 4. Fingerprint fallbacks
    // ──────────────────────────────────────────────────────────────────────

    public function test_core_asset_query_string_alone_does_not_raise_an_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response(
                '<html><head><script src="/wp-includes/js/wp-emoji-release.min.js?ver=6.8.1"></script>'
                .'</head><body>x</body></html>',
                200,
            ),
            'https://example.com/readme.html' => Http::response('', 404),
        ]);

        // Every site in this fleet sits behind a page cache, so ver= reports the
        // core that was current when the page was stored. campsite.fr runs
        // 6.9.4 and serves 6.9.4 here — but webcompare.com runs 7.0.3 and serves
        // an asset stamped years earlier. A stale stamp is always a real past
        // release, so it survives the plausibility guard and reads as a
        // trustworthy "outdated" that is simply false.
        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
        $this->assertSame(0, Insight::withoutGlobalScopes()->count());
    }

    public function test_plugin_asset_versions_are_not_mistaken_for_the_core(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            // A plugin's ver= is the PLUGIN's version; reading it as the core's
            // would report a wildly wrong number.
            'https://example.com/' => Http::response(
                '<html><head><link href="/wp-content/plugins/aawp/style.css?ver=5.0.5" /></head><body>x</body></html>',
                200,
            ),
            'https://example.com/readme.html' => Http::response('not found', 404),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    /**
     * Real bug reproduction: webcompare.com reported "3.7.1" — the version of
     * jQuery, bundled and served from wp-includes/js/jquery/, not the core.
     * A blanket wp-includes/…?ver= match used to read it as the core's.
     */
    public function test_bundled_third_party_libraries_are_not_mistaken_for_the_core(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response(
                '<html><head><script src="/wp-includes/js/jquery/jquery.min.js?ver=3.7.1"></script>'
                .'</head><body>x</body></html>',
                200,
            ),
            'https://example.com/readme.html' => Http::response('not found', 404),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    /**
     * Even a whitelisted core asset is rejected once the plausibility guard
     * sees it does not correspond to any release WordPress.org still lists
     * and is older than the oldest branch it does — the last line of defence
     * when a fingerprint is simply wrong.
     */
    public function test_implausible_version_from_a_core_asset_is_rejected(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(latest: '7.0.2', insecure: ['6.4.1']), 200),
            'https://example.com/' => Http::response(
                '<html><head><script src="/wp-includes/js/wp-embed.min.js?ver=1.2.3"></script>'
                .'</head><body>x</body></html>',
                200,
            ),
            'https://example.com/readme.html' => Http::response('not found', 404),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    public function test_falls_back_to_readme(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/readme.html' => Http::response(
                '<html><body><h1>WordPress</h1><br /> Version 6.7.2</body></html>',
                200,
            ),
            'https://example.com/' => Http::response('<html><head></head><body>x</body></html>', 200),
        ]);

        $this->assertSame(1, (new WordPressVersionService)->detectForSite($site));
        $this->assertSame(
            '6.7.2',
            Insight::withoutGlobalScopes()->first()->payload['installed_version'],
        );
    }

    /**
     * Real bug reproduction: webcompare.de reported "2" — the "2" in "GNU
     * General Public License) version 2", the licence paragraph every
     * default readme.html carries. Modern WordPress readme.html never
     * states the core version in its text at all (verified against the
     * shipped WordPress/WordPress readme.html), so this now correctly
     * yields no candidate rather than the GPL version number.
     */
    public function test_readme_license_paragraph_is_never_read_as_the_version(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/readme.html' => Http::response(
                '<html><body>'
                .'<h1 id="logo"><a href="https://wordpress.org/">'
                .'<img alt="WordPress" src="wp-admin/images/wordpress-logo.png" /></a></h1>'
                .'<h2>License</h2>'
                .'<p>WordPress is free software, and is released under the terms of the '
                .'<abbr>GPL</abbr> (GNU General Public License) version 2 or (at your option) '
                .'any later version. See <a href="license.txt">license.txt</a>.</p>'
                .'</body></html>',
                200,
            ),
            'https://example.com/' => Http::response('<html><head></head><body>x</body></html>', 200),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 5. A hardened site yields no verdict rather than a guess
    // ──────────────────────────────────────────────────────────────────────

    public function test_undetectable_version_creates_no_insight(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response('<html><head></head><body>x</body></html>', 200),
            'https://example.com/readme.html' => Http::response('not found', 404),
        ]);

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
    }

    // ──────────────────────────────────────────────────────────────────────
    // 6. Non-WordPress sites are out of scope
    // ──────────────────────────────────────────────────────────────────────

    public function test_non_wordpress_site_is_skipped(): void
    {
        $site = $this->makeSite(type: 'laravel');
        Http::fake();

        $this->assertSame(0, (new WordPressVersionService)->detectForSite($site));
        Http::assertNothingSent();
    }

    // ──────────────────────────────────────────────────────────────────────
    // 7. The remediation names the vendored-core trap
    // ──────────────────────────────────────────────────────────────────────

    public function test_payload_explains_the_vendored_core_remediation(): void
    {
        $site = $this->makeSite();

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('6.9.4'), 200),
        ]);

        (new WordPressVersionService)->detectForSite($site);

        // Updating in production alone is silently reverted by the next deploy,
        // so the insight has to say so.
        $this->assertStringContainsString(
            're-vendor',
            Insight::withoutGlobalScopes()->first()->payload['remediation'],
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // 8. Idempotence
    // ──────────────────────────────────────────────────────────────────────

    public function test_previous_insight_is_cleared_once_updated(): void
    {
        $site = $this->makeSite();

        Insight::create([
            'team_id' => $site->team_id,
            'site' => $site->primary_domain,
            'site_id' => $site->id,
            'type' => InsightType::OUTDATED_CMS->value,
            'severity' => InsightSeverity::WARNING->value,
            'title' => 'stale finding',
            'payload' => [],
            'impact_score' => 40,
            'detected_at' => now()->subDay(),
        ]);

        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('7.0.2'), 200),
        ]);

        (new WordPressVersionService)->detectForSite($site);

        $this->assertDatabaseMissing('insights', [
            'site_id' => $site->id,
            'type' => InsightType::OUTDATED_CMS->value,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // 9. The release list is fetched once for the whole fleet
    // ──────────────────────────────────────────────────────────────────────

    public function test_release_list_is_cached_across_sites(): void
    {
        Http::fake([
            'api.wordpress.org/*' => Http::response($this->fakeReleases(), 200),
            'https://example.com/' => Http::response($this->pageWithGenerator('7.0.2'), 200),
        ]);

        $service = new WordPressVersionService;
        $service->detectForSite($this->makeSite());
        $service->detectForSite($this->makeSite());

        // The answer is identical for every site; asking twice would waste a
        // round trip per site per day for nothing.
        Http::assertSentCount(3);
    }
}
