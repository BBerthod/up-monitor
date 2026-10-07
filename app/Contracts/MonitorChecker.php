<?php

namespace App\Contracts;

use App\DTOs\CheckResult;
use App\Models\Monitor;

interface MonitorChecker
{
    public function check(Monitor $monitor): CheckResult;
}
