<?php

namespace App\DTOs;

readonly class LighthouseResult
{
    public function __construct(
        public int $performance,
        public int $accessibility,
        public int $bestPractices,
        public int $seo,
        public ?float $lcp = null,
        public ?float $fcp = null,
        public ?float $cls = null,
        public ?float $tbt = null,
        public ?float $speedIndex = null,
        public ?float $lcpObserved = null,
        public ?float $benchmarkIndex = null,
    ) {}
}
