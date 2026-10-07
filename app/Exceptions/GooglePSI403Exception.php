<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google PageSpeed Insights API returns HTTP 403.
 * This indicates the API key is invalid, revoked, or lacks permission.
 * The affected key should be disabled; do not retry with the same key.
 */
class GooglePSI403Exception extends RuntimeException
{
    public function __construct(string $keyIndex, string $body = '')
    {
        parent::__construct("Google PSI API key #{$keyIndex} returned 403 (invalid/revoked). Body: {$body}");
    }
}
