<?php

namespace App\Enums;

enum IncidentCause: string
{
    case TIMEOUT = 'timeout';
    case STATUS_CODE = 'status_code';
    case KEYWORD = 'keyword';
    case SSL = 'ssl';
    case ERROR = 'error';
    case FUNCTIONAL = 'functional';
    case BUSINESS_REGRESSION = 'business_regression';
    case FAILED_SMOKE_TEST = 'failed_smoke_test';

    /** Human-readable label for public display (e.g. status pages). */
    public function label(): string
    {
        return match ($this) {
            self::TIMEOUT => 'Response timeout',
            self::STATUS_CODE => 'HTTP error',
            self::KEYWORD => 'Content check failed',
            self::SSL => 'SSL certificate issue',
            self::ERROR => 'Connection error',
            self::FUNCTIONAL => 'Functional check failed',
            self::BUSINESS_REGRESSION => 'Business KPI regression',
            self::FAILED_SMOKE_TEST => 'Post-deploy smoke test failed',
        };
    }
}
