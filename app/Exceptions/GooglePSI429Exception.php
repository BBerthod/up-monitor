<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google PageSpeed Insights API returns HTTP 429.
 * This indicates the daily quota for the current key has been exhausted.
 * Callers should rotate to the next key or back off.
 */
class GooglePSI429Exception extends RuntimeException
{
    public function __construct(string $keyIndex, string $body = '')
    {
        parent::__construct("Google PSI API key #{$keyIndex} returned 429 (quota exceeded). Body: {$body}");
    }
}
