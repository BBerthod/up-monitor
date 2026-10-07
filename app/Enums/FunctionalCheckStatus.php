<?php

namespace App\Enums;

enum FunctionalCheckStatus: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
    case PENDING = 'pending';
}
