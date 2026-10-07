<?php

namespace App\Enums;

enum KpiSource: string
{
    case GSC = 'gsc';
    case GA4 = 'ga4';
    case BING = 'bing';
    case ADSENSE = 'adsense';
    case AMAZON = 'amazon';
    case CRUX = 'crux';
    case UPTIME = 'uptime';
    case TTFB = 'ttfb';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GSC => 'Google Search Console',
            self::GA4 => 'Google Analytics 4',
            self::BING => 'Bing Webmaster',
            self::ADSENSE => 'Google AdSense',
            self::AMAZON => 'Amazon Associates',
            self::CRUX => 'Chrome UX Report (field data)',
            self::UPTIME => 'Uptime',
            self::TTFB => 'TTFB',
            self::CUSTOM => 'Custom',
        };
    }
}
