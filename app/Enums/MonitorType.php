<?php

namespace App\Enums;

enum MonitorType: string
{
    case HTTP = 'http';
    case PING = 'ping';
    case PORT = 'port';
    case DNS = 'dns';

    public function label(): string
    {
        return match ($this) {
            self::HTTP => 'HTTP(S)',
            self::PING => 'Ping (ICMP)',
            self::PORT => 'TCP Port',
            self::DNS => 'DNS Record',
        };
    }
}
