<?php

namespace App\Enums;

enum WarmRunStatus: string
{
    case RUNNING = 'running';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
