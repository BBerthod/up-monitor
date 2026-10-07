<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'webpush' => [
        'vapid' => [
            'public_key' => env('VAPID_PUBLIC_KEY'),
            'private_key' => env('VAPID_PRIVATE_KEY'),
        ],
    ],

    'dokploy' => [
        'webhook_secret' => env('DOKPLOY_WEBHOOK_SECRET'),
        'api_token' => env('DOKPLOY_API_TOKEN'),
        'base_url' => env('DOKPLOY_BASE_URL', 'https://dokploy.example.com'),
    ],

    'ai' => [
        // Set AI_PROVIDER=gemini to enable LLM-generated narrations in SEO digests.
        // Any other value (or omitted) falls back to the deterministic NullProvider.
        'provider' => env('AI_PROVIDER', 'null'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gemini-2.5-flash'),
    ],

    'bing' => [
        // Bing Webmaster Tools API key. When empty, Bing collection is skipped.
        'api_key' => env('BING_WEBMASTER_API_KEY'),
    ],

    'google' => [
        // Legacy single-key support (kept for backward compatibility).
        'pagespeed_api_key' => env('GOOGLE_PAGESPEED_API_KEY'),

        // Multi-key rotation: comma-separated list of API keys.
        // Example: GOOGLE_PAGESPEED_API_KEYS="key1,key2,key3"
        // When set, keys are rotated round-robin; each key has a daily quota
        // counter capped at 400 calls (conservative margin below Google's 500/day limit).
        // If empty, falls back to GOOGLE_PAGESPEED_API_KEY (single key).
        // If both are empty, the API is called without a key (very low quota).
        'pagespeed_api_keys' => env('GOOGLE_PAGESPEED_API_KEYS'),

        // Service-account credentials for GSC and GA4 APIs.
        // Split from a service-account JSON key file into two env vars.
        // GOOGLE_GSC_CLIENT_EMAIL  = the "client_email" field
        // GOOGLE_GSC_PRIVATE_KEY   = the "private_key" field (newlines as \n or literal)
        'client_email' => env('GOOGLE_GSC_CLIENT_EMAIL'),
        'private_key' => env('GOOGLE_GSC_PRIVATE_KEY'),

        // GA4 numeric property ID (e.g. "123456789").
        'ga4_property_id' => env('GOOGLE_GA4_PROPERTY_ID'),
    ],

    // AdSense Management API v2 — real per-site earnings.
    //
    // Uses the same service-account credentials as GSC/GA4 above, with the
    // adsense.readonly scope. IMPORTANT: unlike Search Console, AdSense has no
    // per-property sharing — the service account must be invited as a user on
    // the AdSense account itself, which grants account-wide read access.
    'adsense' => [
        // Default publisher account ("pub-XXXXXXXXXXXXXXXX"), used for every
        // site that does not override it via Site::adsense_account_id. Most
        // fleets serve all their sites from a single account.
        'account_id' => env('ADSENSE_ACCOUNT_ID'),
    ],

];
