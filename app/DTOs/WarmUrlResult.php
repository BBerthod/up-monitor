<?php

namespace App\DTOs;

readonly class WarmUrlResult
{
    public function __construct(
        public string $url,
        public int $statusCode,
        public string $cacheStatus, // 'hit', 'miss', 'unknown'
        public int $responseTimeMs,
        public ?string $errorMessage = null,
    ) {}

    public function isHit(): bool
    {
        return $this->cacheStatus === 'hit';
    }

    public function isMiss(): bool
    {
        return $this->cacheStatus === 'miss';
    }

    public function isError(): bool
    {
        return $this->errorMessage !== null;
    }
}
