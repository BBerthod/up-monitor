<?php

namespace App\Exceptions;

use RuntimeException;

class UnsafeUrlException extends RuntimeException
{
    public function __construct(string $url, string $reason = '')
    {
        $message = "URL is not safe: {$url}";
        if ($reason !== '') {
            $message .= " ({$reason})";
        }

        parent::__construct($message);
    }
}
