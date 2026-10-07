<?php

namespace App\Enums;

enum IncidentSeverity: string
{
    case CRITICAL = 'critical';
    case MAJOR = 'major';
    case MINOR = 'minor';
    case WARNING = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critical',
            self::MAJOR => 'Major',
            self::MINOR => 'Minor',
            self::WARNING => 'Warning',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CRITICAL => '#dc2626',
            self::MAJOR => '#f97316',
            self::MINOR => '#eab308',
            self::WARNING => '#6b7280',
        };
    }
}
