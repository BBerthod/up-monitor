<?php

namespace App\DTOs;

use App\Enums\CheckStatus;
use App\Enums\IncidentCause;

readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public ?int $responseTimeMs = null,
        public ?int $statusCode = null,
        public ?\DateTime $sslExpiresAt = null,
        public ?string $errorMessage = null,
        public ?IncidentCause $cause = null,
    ) {}
}
