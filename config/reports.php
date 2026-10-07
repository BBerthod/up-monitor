<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Recipients
    |--------------------------------------------------------------------------
    |
    | Fallback recipient list used when a Site has report_frequency != none but
    | no explicit report_recipients configured. Comma-separated in the env var,
    | trimmed and filtered for empty entries.
    |
    */
    'default_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SITE_REPORT_RECIPIENTS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Send Time
    |--------------------------------------------------------------------------
    |
    | Daily time (HH:MM, server timezone) at which DispatchSiteReports runs and
    | selects the sites due for a report that day.
    |
    */
    'send_time' => env('SITE_REPORT_SEND_TIME', '07:30'),
];
