<?php

namespace Tests\Unit\Enums;

use App\Enums\InsightType;
use PHPUnit\Framework\TestCase;

class InsightTypeBusinessWeightTest extends TestCase
{
    /**
     * Every InsightType case must return a businessWeight() without throwing.
     * Guards against adding a new type without updating the match expression.
     */
    public function test_all_cases_have_a_business_weight(): void
    {
        foreach (InsightType::cases() as $type) {
            $weight = $type->businessWeight();
            $this->assertIsInt($weight, "{$type->name} businessWeight() must return an int.");
            $this->assertGreaterThanOrEqual(0, $weight);
            $this->assertLessThanOrEqual(10, $weight);
        }
    }

    public function test_revenue_at_risk_has_highest_weight(): void
    {
        $this->assertSame(10, InsightType::REVENUE_AT_RISK->businessWeight());
    }

    public function test_affiliate_leak_has_weight_nine(): void
    {
        $this->assertSame(9, InsightType::AFFILIATE_LEAK->businessWeight());
    }

    public function test_uptime_incident_has_weight_seven(): void
    {
        $this->assertSame(7, InsightType::UPTIME_INCIDENT->businessWeight());
    }

    public function test_ssl_expiry_has_weight_six(): void
    {
        $this->assertSame(6, InsightType::SSL_EXPIRY->businessWeight());
    }

    public function test_domain_expiry_has_weight_six(): void
    {
        $this->assertSame(6, InsightType::DOMAIN_EXPIRY->businessWeight());
    }

    public function test_striking_distance_has_weight_zero(): void
    {
        $this->assertSame(0, InsightType::STRIKING_DISTANCE->businessWeight());
    }

    public function test_ctr_change_has_lower_weight_than_revenue_at_risk(): void
    {
        $this->assertLessThan(
            InsightType::REVENUE_AT_RISK->businessWeight(),
            InsightType::CTR_CHANGE->businessWeight(),
        );
    }

    public function test_revenue_at_risk_beats_ctr_change(): void
    {
        $this->assertGreaterThan(
            InsightType::CTR_CHANGE->businessWeight(),
            InsightType::REVENUE_AT_RISK->businessWeight(),
        );
    }
}
