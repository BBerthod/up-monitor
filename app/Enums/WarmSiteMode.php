<?php

namespace App\Enums;

enum WarmSiteMode: string
{
    case URLS = 'urls';
    case SITEMAP = 'sitemap';

    public function label(): string
    {
        return match ($this) {
            self::URLS => 'Manual URLs',
            self::SITEMAP => 'Sitemap',
        };
    }
}
