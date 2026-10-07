<?php

namespace App\Enums;

enum IngestEventType: string
{
    case LOG = 'log';
    case JOB_FAILED = 'job_failed';
    case HEALTH_CHECK = 'health_check';
    case DEPLOYMENT = 'deployment';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::LOG => 'Log',
            self::JOB_FAILED => 'Job Failed',
            self::HEALTH_CHECK => 'Health Check',
            self::DEPLOYMENT => 'Deployment',
            self::CUSTOM => 'Custom',
        };
    }
}
