<?php

namespace App\Enums;

enum InsightDomain: string
{
    case AVAILABILITY = 'availability';
    case SEO_BUSINESS = 'seo_business';
    case INFRASTRUCTURE = 'infrastructure';
    case ALERTING = 'alerting';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABILITY => 'Availability',
            self::SEO_BUSINESS => 'SEO & Business',
            self::INFRASTRUCTURE => 'Infrastructure',
            self::ALERTING => 'Alerting',
        };
    }

    /**
     * Return the InsightType values (strings) that belong to this domain.
     *
     * Iterates InsightType::cases() and filters on domain() — the mapping is
     * authoritative on InsightType, InsightDomain is the query entrypoint.
     * Result is intentionally string[] (enum values) so it can be used
     * directly in whereIn() without further mapping.
     *
     * Both enums live in App\Enums so no import is needed.
     *
     * @return string[]
     */
    public static function typesForDomain(self $domain): array
    {
        return array_values(
            array_map(
                fn (InsightType $t): string => $t->value,
                array_filter(
                    InsightType::cases(),
                    fn (InsightType $t): bool => $t->domain() === $domain,
                ),
            )
        );
    }
}
