<?php

namespace App\Enums;

enum KpiRegressionSeverity: string
{
    case MINOR = 'minor';
    case MAJOR = 'major';

    public function label(): string
    {
        return match ($this) {
            self::MINOR => 'Minor',
            self::MAJOR => 'Major',
        };
    }
}
