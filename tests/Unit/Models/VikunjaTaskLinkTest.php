<?php

namespace Tests\Unit\Models;

use App\Enums\InsightType;
use App\Models\Insight;
use App\Models\VikunjaTaskLink;
use Tests\TestCase;

/**
 * The scope key is the load-bearing part of this integration: it is what makes a
 * link survive the nightly delete/recreate of the insight it describes. A change
 * in these expectations means existing links stop matching and every problem gets
 * a second card.
 */
class VikunjaTaskLinkTest extends TestCase
{
    private function insight(array $attributes): Insight
    {
        return (new Insight)->forceFill($attributes);
    }

    public function test_ssl_expiry_is_keyed_by_monitor(): void
    {
        // A certificate belongs to one host:port, so two monitors on the same
        // site are two problems — mirrors the per-monitor dispatch in DispatchInsights.
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => InsightType::SSL_EXPIRY,
            'site_id' => 7,
            'monitor_id' => 42,
        ]));

        $this->assertSame('monitor:42|ssl_expiry', $key);
    }

    public function test_perf_regression_is_keyed_by_monitor(): void
    {
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => InsightType::PERF_REGRESSION,
            'site_id' => 7,
            'monitor_id' => 42,
        ]));

        $this->assertSame('monitor:42|perf_regression', $key);
    }

    public function test_server_health_is_keyed_by_server(): void
    {
        // One dead host must produce one card, not one per hosted site.
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => InsightType::SERVER_HEALTH,
            'server_id' => 3,
            'site_id' => 7,
        ]));

        $this->assertSame('server:3|server_health', $key);
    }

    public function test_other_types_are_keyed_by_site_even_when_a_monitor_is_present(): void
    {
        // Detectors deduplicate per site; the representative monitor can change
        // between runs, so keying on it would orphan the link.
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => InsightType::CONTENT_DECAY,
            'site_id' => 7,
            'monitor_id' => 42,
        ]));

        $this->assertSame('site:7|content_decay', $key);
    }

    public function test_legacy_rows_fall_back_to_the_hostname(): void
    {
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => InsightType::SITEMAP_HEALTH,
            'site' => 'Example.COM',
            'site_id' => null,
            'monitor_id' => null,
        ]));

        // Lowercased: casing drift must not fork the key into two links.
        $this->assertSame('host:example.com|sitemap_health', $key);
    }

    public function test_string_typed_insights_are_accepted(): void
    {
        // Rows written before the enum cast existed still carry a raw string.
        $key = VikunjaTaskLink::scopeKeyFor($this->insight([
            'type' => 'zombie_page',
            'site_id' => 9,
        ]));

        $this->assertSame('site:9|zombie_page', $key);
    }
}
