<?php

namespace App\Services\Ai;

interface AiProvider
{
    /**
     * Generate a short narrative summary from a structured system prompt and a
     * JSON-serialisable array of already-computed facts. The provider must NOT
     * invent numbers — it only narrates the facts it is given.
     *
     * Returns the narrative text. Must never throw for callers: implementations
     * handle their own errors and degrade gracefully (the binding falls back to
     * NullProvider).
     */
    public function narrate(string $systemPrompt, array $facts): string;

    /**
     * Whether this provider is actually configured/operational.
     */
    public function isAvailable(): bool;
}
