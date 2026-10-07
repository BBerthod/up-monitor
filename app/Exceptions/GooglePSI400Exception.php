<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Google PageSpeed Insights API returns HTTP 400.
 * This indicates the audited URL is not a Lighthouse-analyzable page (e.g. a
 * JSON API endpoint or a redirect target) rather than a transient failure.
 * Retrying — with the same key or a different one — will never succeed, so
 * callers should stop the job cleanly instead of retrying.
 */
class GooglePSI400Exception extends RuntimeException
{
    public function __construct(string $keyIndex, string $body = '')
    {
        parent::__construct("Google PSI API key #{$keyIndex} returned 400 (bad request / unauditable URL). Body: {$body}");
    }
}
