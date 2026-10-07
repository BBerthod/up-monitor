<?php

namespace Tests\Unit\Enums;

use App\Enums\InsightDomain;
use App\Enums\InsightType;
use PHPUnit\Framework\TestCase;

class InsightTypeDomainTest extends TestCase
{
    /**
     * Every InsightType case must return a domain without throwing.
     * This guards against adding a new type without mapping it.
     */
    public function test_all_cases_have_a_domain(): void
    {
        foreach (InsightType::cases() as $type) {
            $domain = $type->domain();
            $this->assertInstanceOf(InsightDomain::class, $domain, "{$type->name} is missing a domain mapping.");
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Explicit domain assertions for each decided mapping
    // ──────────────────────────────────────────────────────────────────────

    public function test_striking_distance_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::STRIKING_DISTANCE->domain());
    }

    public function test_traffic_change_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::TRAFFIC_CHANGE->domain());
    }

    public function test_position_change_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::POSITION_CHANGE->domain());
    }

    public function test_ctr_change_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::CTR_CHANGE->domain());
    }

    public function test_health_drop_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::HEALTH_DROP->domain());
    }

    public function test_perf_regression_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::PERF_REGRESSION->domain());
    }

    public function test_uptime_incident_is_availability(): void
    {
        $this->assertSame(InsightDomain::AVAILABILITY, InsightType::UPTIME_INCIDENT->domain());
    }

    public function test_content_decay_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::CONTENT_DECAY->domain());
    }

    public function test_revenue_at_risk_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::REVENUE_AT_RISK->domain());
    }

    public function test_affiliate_leak_is_seo_business(): void
    {
        $this->assertSame(InsightDomain::SEO_BUSINESS, InsightType::AFFILIATE_LEAK->domain());
    }

    public function test_server_health_is_infrastructure(): void
    {
        $this->assertSame(InsightDomain::INFRASTRUCTURE, InsightType::SERVER_HEALTH->domain());
    }

    // ──────────────────────────────────────────────────────────────────────
    // InsightDomain metadata
    // ──────────────────────────────────────────────────────────────────────

    public function test_domain_label_returns_string_for_all_cases(): void
    {
        foreach (InsightDomain::cases() as $domain) {
            $this->assertIsString($domain->label(), "{$domain->name} label() must return a string.");
            $this->assertNotEmpty($domain->label());
        }
    }
}
