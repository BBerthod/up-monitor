<?php

namespace App\DTOs;

readonly class FunctionalResult
{
    public function __construct(
        public bool $passed,
        public int $durationMs,
        public array $details,
        public ?string $errorMessage = null,
    ) {}
}
