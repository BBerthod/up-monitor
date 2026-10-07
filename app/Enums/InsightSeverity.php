<?php

namespace App\Enums;

enum InsightSeverity: string
{
    case INFO = 'info';
    case OPPORTUNITY = 'opportunity';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'Info',
            self::OPPORTUNITY => 'Opportunity',
            self::WARNING => 'Warning',
            self::CRITICAL => 'Critical',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::INFO => '#6b7280',
            self::OPPORTUNITY => '#10b981',
            self::WARNING => '#f97316',
            self::CRITICAL => '#dc2626',
        };
    }
}
