<?php

if (! function_exists('flash_with_link')) {
    function flash_with_link(string $type, string $message, string $link, string $linkText): void
    {
        session()->flash($type, $message);
        session()->flash('link', $link);
        session()->flash('linkText', $linkText);
    }
}

if (! function_exists('flash_success_with_link')) {
    function flash_success_with_link(string $message, string $link, string $linkText = 'View'): void
    {
        flash_with_link('success', $message, $link, $linkText);
    }
}

if (! function_exists('flash_error_with_link')) {
    function flash_error_with_link(string $message, string $link, string $linkText = 'View'): void
    {
        flash_with_link('error', $message, $link, $linkText);
    }
}

if (! function_exists('flash_warning_with_link')) {
    function flash_warning_with_link(string $message, string $link, string $linkText = 'View'): void
    {
        flash_with_link('warning', $message, $link, $linkText);
    }
}

if (! function_exists('flash_message_with_link')) {
    function flash_message_with_link(string $message, string $link, string $linkText = 'View'): void
    {
        flash_with_link('message', $message, $link, $linkText);
    }
}
