<?php

namespace App\Support;

use App\Models\Monitor;

/**
 * Shared "is this URL a real Lighthouse-auditable page?" rule.
 *
 * Excludes JSON/health/status/redirect-guard endpoints and monitors expecting
 * a 3xx status: PSI answers 400 on these, a permanent failure rather than a
 * transient one.
 *
 * Used by BOTH:
 *  - `DispatchLighthouseAudits` — skip dispatch before burning a job attempt
 *    on a URL PSI will always reject.
 *  - `PerfRegressionDetector` — skip the regression comparison itself. A pair
 *    of stored scores can predate this rule (or predate `lighthouse_enabled`
 *    being turned off) and stay frozen forever: production compared two
 *    scores captured while PSI followed a 302 to a third-party product page
 *    and audited that instead of the monitored site — a score of 15 on an
 *    Amazon listing has nothing to say about the monitor it was attached to.
 *
 * One regex, not two copies that can drift apart.
 */
final class LighthouseAuditability
{
    public static function isAuditable(Monitor $monitor): bool
    {
        $path = parse_url($monitor->url, PHP_URL_PATH) ?? '';

        if (preg_match('#/(api|health|status|go)/#i', $path) === 1) {
            return false;
        }

        if ($monitor->expected_status_code !== null
            && $monitor->expected_status_code >= 300
            && $monitor->expected_status_code < 400) {
            return false;
        }

        return true;
    }
}
