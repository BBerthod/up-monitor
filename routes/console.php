<?php

use App\Console\Commands\CheckHeartbeatsCommand;
use App\Console\Commands\CheckSchedulerHealthCommand;
use App\Console\Commands\CheckServerHeartbeatCommand;
use App\Console\Commands\ResolveStaleIncidentsCommand;
use App\Jobs\CheckDomainExpiry;
use App\Jobs\DispatchBrokenRedirectChecks;
use App\Jobs\DispatchChecks;
use App\Jobs\DispatchFunctionalChecks;
use App\Jobs\DispatchInsights;
use App\Jobs\DispatchKpiCollection;
use App\Jobs\DispatchLighthouseAudits;
use App\Jobs\DispatchRevenueAlerts;
use App\Jobs\DispatchSeoAlerts;
use App\Jobs\DispatchServerAlerts;
use App\Jobs\DispatchServerMetrics;
use App\Jobs\DispatchSiteReports;
use App\Jobs\DispatchWarmRuns;
use App\Jobs\PruneFunctionalCheckResults;
use App\Jobs\PruneIngestEvents;
use App\Jobs\PruneKeywordMetrics;
use App\Jobs\PruneKpiSnapshots;
use App\Jobs\PruneLighthouseScores;
use App\Jobs\PruneMonitorChecks;
use App\Jobs\PruneNotificationLogs;
use App\Jobs\PrunePageMetrics;
use App\Jobs\PruneServerMetrics;
use App\Jobs\PruneWarmRuns;
use App\Jobs\SendDigests;
use App\Jobs\SendWeeklyReports;
use App\Jobs\SyncVikunjaTasks;
use Illuminate\Support\Facades\Schedule;

// Single-node deployment: onOneServer() removed — it requires a shared atomic cache and
// adds fragility without benefit on a single node. If a multi-node setup is ever introduced,
// re-add onOneServer() to all schedule entries that modify shared state.

Schedule::job(new DispatchChecks)->everyMinute()->withoutOverlapping();
Schedule::job(new DispatchLighthouseAudits)->everySixHours()->withoutOverlapping();
Schedule::job(new DispatchFunctionalChecks)->everyMinute()->withoutOverlapping();
Schedule::job(new PruneFunctionalCheckResults)->daily()->withoutOverlapping();
Schedule::job(new DispatchWarmRuns)->everyMinute()->withoutOverlapping();
Schedule::job(new PruneWarmRuns)->daily()->withoutOverlapping();
Schedule::job(new PruneMonitorChecks)->daily()->withoutOverlapping();
Schedule::job(new PruneNotificationLogs)->daily()->withoutOverlapping();
Schedule::job(new PruneIngestEvents)->daily()->withoutOverlapping();
Schedule::job(new PruneLighthouseScores)->daily()->withoutOverlapping();
Schedule::job(new PrunePageMetrics)->daily()->withoutOverlapping();
Schedule::job(new PruneKeywordMetrics)->daily()->withoutOverlapping();
Schedule::job(new PruneKpiSnapshots)->daily()->withoutOverlapping();
Schedule::job(new SendWeeklyReports)->weeklyOn(1, '08:00')->withoutOverlapping();

// Link any unlinked monitors to their Site by domain before the KPI/insight runs,
// so newly added monitors are attributed to the right site. Idempotent.
Schedule::command('sites:link-monitors')->dailyAt('02:45')->withoutOverlapping();

// Safety net for sites.vikunja_project_id, on top of the automatic mapping fired
// by SiteObserver on Site::created: catches sites created outside Eloquent (raw
// inserts, seeders with events off) and sites whose match only appeared later
// (their own Vikunja project created after the site itself). --apply only ever
// fills a NULL vikunja_project_id — it never touches an existing mapping, so
// running it unattended is safe. Daily like the other site-upkeep sweeps above;
// nothing about board routing is urgent enough to warrant tighter polling.
Schedule::command('vikunja:doctor', ['--map', '--apply'])->dailyAt('02:47')->withoutOverlapping();

// Business KPI collection: TTFB probes + GSC/GA4 snapshots, then regression detection.
// Runs daily at 03:00 UTC — off-peak to avoid TTFB probes polluting cache-warm metrics.
Schedule::job(new DispatchKpiCollection)->dailyAt('03:00')->withoutOverlapping();

// Domain expiry: RDAP check for all active sites. Runs between KPI collection (03:00)
// and DispatchInsights (04:00) — domain data changes rarely, daily granularity is sufficient.
Schedule::job(new CheckDomainExpiry)->dailyAt('03:30')->withoutOverlapping();

// Striking-distance insights refresh: runs after KPI collection (04:00) so snapshots are fresh.
Schedule::job(new DispatchInsights)->dailyAt('04:00')->withoutOverlapping();

// SEO alerts: push fresh WARNING/CRITICAL insights to channels at 05:00, after insights are persisted.
Schedule::job(new DispatchSeoAlerts)->dailyAt('05:00')->withoutOverlapping();

// Vikunja board sync: turn persistent problems into Kanban cards, close cards whose
// problem is gone, and acknowledge insights whose card a human completed.
// Every 15 minutes rather than on the daily insight cycle, because promotion is driven
// by how long a problem has lasted — a sweep that only ran at 04:00 could not tell a
// six-hour outage from a six-minute one. No-op while VIKUNJA_ENABLED is false.
Schedule::job(new SyncVikunjaTasks)->everyFifteenMinutes()->withoutOverlapping();

// Server resource metrics: pull CPU/RAM/disk from the Dokploy monitoring API
// every 5 minutes and raise SERVER_HEALTH insights on threshold breaches.
Schedule::job(new DispatchServerMetrics)->everyFiveMinutes()->withoutOverlapping();

// Dead-man switch: declare a server silent and create a CRITICAL Insight when no
// metric has been received for 15 minutes (agent may have crashed or lost network).
Schedule::command(CheckServerHeartbeatCommand::class, ['--stale-minutes=15'])
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Immediate infrastructure notifications: notify WARNING/CRITICAL server and missed
// heartbeat alerts every 5 minutes instead of waiting for DispatchSeoAlerts at 05:00.
// SEO alerts are not included — they continue to fire once daily.
Schedule::job(new DispatchServerAlerts)->everyFiveMinutes()->withoutOverlapping();

// Dead-man switches for scheduled work running outside Up (wp-cron, nightly
// rebuilds, sitemap regeneration). Every five minutes: the cost is one indexed
// query, and a job whose period is measured in minutes deserves to be caught
// within minutes rather than at the next daily sweep.
Schedule::command(CheckHeartbeatsCommand::class)->everyFiveMinutes()->withoutOverlapping();

// Prune server metrics older than the retention window (default 30 days).
Schedule::job(new PruneServerMetrics)->daily()->withoutOverlapping();

// Affiliate redirect health: walk the outbound /go/{ASIN} hop on every affiliate
// site. Deliberately NOT part of the daily 04:00 insight cycle — a broken rewrite
// rule earns nothing from the second it ships, while the site keeps serving 200 to
// every other check. Production lost several days of affiliate revenue exactly this
// way. Every 30 minutes bounds the exposure to minutes instead of a day, at a cost
// of ~10 page fetches plus ~25 header-only probes per site.
Schedule::job(new DispatchBrokenRedirectChecks)->everyThirtyMinutes()->withoutOverlapping();

// Immediate revenue-blocking notifications: same rationale as DispatchServerAlerts.
// Waiting for the 05:00 SEO alert run would defeat the 30-minute detection above.
Schedule::job(new DispatchRevenueAlerts)->everyFiveMinutes()->withoutOverlapping();

// Weekly AI digest: Monday at 09:00 — 1h after the weekly report (08:00), giving it fresh uptime data.
Schedule::job(new SendDigests)->weeklyOn(1, '09:00')->withoutOverlapping();

// Prune failed_jobs older than 7 days to prevent unbounded table growth.
Schedule::command('queue:prune-failed --hours=168')->weekly()->withoutOverlapping();

// Safety net: resolve zombie incidents (monitors that are UP but have an open incident
// longer than 24 h). Runs daily at 01:00 UTC, before the KPI collection at 03:00.
Schedule::command(ResolveStaleIncidentsCommand::class, ['--force', '--hours=24'])
    ->dailyAt('01:00')
    ->withoutOverlapping();

// Self-monitoring: alert and auto-recover if the scheduler or worker appears stalled.
// Runs every 5 minutes; a stall is declared when no MonitorCheck has been written for
// more than 10 minutes (2× the default 5-minute monitor interval).
Schedule::command(CheckSchedulerHealthCommand::class, ['--stall-minutes=10'])
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Per-site periodic performance reports (weekly/monthly, opt-in per Site).
// DispatchSiteReports itself decides which sites are actually due today; the
// daily run cost is a single indexed query on days nothing is due.
Schedule::job(new DispatchSiteReports)->dailyAt(config('reports.send_time'))->withoutOverlapping();
