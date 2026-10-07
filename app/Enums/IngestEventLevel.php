<?php

namespace App\Enums;

enum IngestEventLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case EMERGENCY = 'emergency';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isAlert(): bool
    {
        return in_array($this, [self::CRITICAL, self::EMERGENCY]);
    }

    public function color(): string
    {
        return match ($this) {
            self::DEBUG => 'gray',
            self::INFO => 'blue',
            self::WARNING => 'orange',
            self::ERROR => 'red',
            self::CRITICAL, self::EMERGENCY => 'dark-red',
        };
    }

    public function notificationCooldownMinutes(): int
    {
        return match ($this) {
            self::CRITICAL, self::EMERGENCY => 5,
            default => 15,
        };
    }
}
