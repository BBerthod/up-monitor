<?php

namespace App\Enums;

enum SmokeTestStatus: string
{
    case PENDING = 'pending';
    case PASSED = 'passed';
    case FAILED = 'failed';
}
