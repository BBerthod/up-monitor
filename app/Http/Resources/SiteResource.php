<?php

namespace App\Http\Resources;

use App\Enums\KpiSource;
use App\Models\KpiSnapshot;
use App\Services\KpiCollector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alias' => $this->alias,
            'organization' => $this->organization,
            'primary_domain' => $this->primary_domain,
            'domains' => $this->domains,
            'locales' => $this->locales,
            'primary_locale' => $this->primary_locale,
            'type' => $this->type,
            'health_endpoint' => $this->health_endpoint,
            'gsc_property' => $this->gsc_property,
            'bing_url' => $this->bing_url,
            'ga4_property' => $this->ga4_property,
            'sitemap_path' => $this->sitemap_path,
            'sitemap_locale_pattern' => $this->sitemap_locale_pattern,
            'key_pages' => $this->key_pages,
            'ad_networks' => $this->ad_networks,
            'merchant_domains' => $this->merchant_domains,
            'amazon_tag' => $this->amazon_tag,
            'dokploy_app_id' => $this->dokploy_app_id,
            'dokploy_resource_type' => $this->dokploy_resource_type,
            'is_active' => $this->is_active,
            'report_frequency' => $this->report_frequency?->value,
            'report_recipients' => $this->report_recipients,
            'last_report_sent_at' => $this->last_report_sent_at?->toIso8601String(),
            'monitors_count' => $this->whenCounted('monitors'),
            // Latest collected KPI snapshots (GSC + TTFB), so API consumers can
            // confirm collection is working and surface SEO numbers per site.
            'kpis' => $this->latestKpis(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Read the most recent KPI snapshots for this site's canonical hostname.
     * The snapshot key is siteNameFromUrl(resolvedPrimaryDomain) — the same key
     * KpiCollector writes and HealthScore/WhatChanged read.
     *
     * @return array<string, float|null>
     */
    private function latestKpis(): array
    {
        $siteKey = app(KpiCollector::class)
            ->siteNameFromUrl('https://'.$this->resolvedPrimaryDomain());

        $val = function (KpiSource $source, string $metric) use ($siteKey): ?float {
            $snapshot = KpiSnapshot::latestFor($siteKey, $source, $metric);

            return $snapshot?->value !== null ? (float) $snapshot->value : null;
        };

        return [
            'gsc_clicks_28d' => $val(KpiSource::GSC, 'clicks_28d'),
            'gsc_impressions_28d' => $val(KpiSource::GSC, 'impressions_28d'),
            'gsc_ctr_28d' => $val(KpiSource::GSC, 'ctr_28d'),
            'gsc_position_28d' => $val(KpiSource::GSC, 'position_28d'),
            'bing_clicks_28d' => $val(KpiSource::BING, 'bing_clicks_28d'),
            'bing_impressions_28d' => $val(KpiSource::BING, 'bing_impressions_28d'),
            'bing_ctr_28d' => $val(KpiSource::BING, 'bing_ctr_28d'),
            'ga4_users_28d' => $val(KpiSource::GA4, 'users_28d'),
            'ttfb_p95_ms' => $val(KpiSource::TTFB, 'ttfb_p95_ms'),
        ];
    }
}
