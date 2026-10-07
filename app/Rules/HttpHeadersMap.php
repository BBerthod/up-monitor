<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a flat header-name => header-value map (e.g. custom/request
 * headers sent with an outbound HTTP request). Header names must be safe
 * identifiers and a small denylist blocks headers that could be abused to
 * replay credentials or spoof the request (Host, Content-Length,
 * Authorization). Shared by monitor and cache-warming custom headers —
 * see StoreMonitorRequest / StoreWarmSiteRequest.
 */
class HttpHeadersMap implements ValidationRule
{
    /**
     * @var array<int, string>
     */
    private const FORBIDDEN = ['host', 'content-length', 'authorization'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $headerValue) {
            if (! is_string($key) || ! preg_match('/^[A-Za-z0-9-]{1,64}$/', $key)) {
                $fail('Each request header name must contain only letters, digits and hyphens (max 64 characters).');

                return;
            }

            if (in_array(strtolower($key), self::FORBIDDEN, true)) {
                $fail("The header \"{$key}\" cannot be overridden.");

                return;
            }

            if (! is_string($headerValue) || mb_strlen($headerValue) > 1024) {
                $fail("The value for header \"{$key}\" must be a string up to 1024 characters.");

                return;
            }

            if (preg_match('/[\r\n\0]/', $headerValue)) {
                $fail("The value for header \"{$key}\" must not contain line breaks.");

                return;
            }
        }
    }
}
