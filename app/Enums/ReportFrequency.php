<?php

namespace App\Enums;

enum ReportFrequency: string
{
    case NONE = 'none';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Aucun',
            self::WEEKLY => 'Hebdomadaire',
            self::MONTHLY => 'Mensuel',
        };
    }
}
