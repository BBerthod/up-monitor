<?php

use App\Http\Controllers\Api\DeployHookController;
use App\Http\Controllers\Api\HeartbeatController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\IngestSourceApiController;
use App\Http\Controllers\Api\InsightDispatchApiController;
use App\Http\Controllers\Api\InsightIngestionController;
use App\Http\Controllers\Api\KpiCollectionApiController;
use App\Http\Controllers\Api\LinkMonitorsApiController;
use App\Http\Controllers\Api\MonitorApiController;
use App\Http\Controllers\Api\NotificationChannelApiController;
use App\Http\Controllers\Api\SearchApiController;
use App\Http\Controllers\Api\ServerMetricController;
use App\Http\Controllers\Api\SiteApiController;
use App\Http\Controllers\Api\StatusPageApiController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// Deploy hooks — auth via X-Dokploy-Secret header (checked inside controller)
Route::post('/deploy-hooks/dokploy', [DeployHookController::class, 'dokploy'])
    ->name('api.deploy-hooks.dokploy')
    ->middleware('throttle:30,1');

// Public status page API (no auth)
Route::get('/status-pages/public/{slug}', [StatusPageApiController::class, 'publicShow'])->name('api.status-pages.public');

// Event ingestion — legacy URL-path token (deprecated, kept for backward compat).
// New integrations should use POST /api/ingest with Authorization: Bearer <token>.
Route::post('/ingest/{token}', [IngestController::class, 'receive'])
    ->name('api.ingest.receive')
    ->middleware('throttle:60,1');

// Event ingestion — Bearer token (preferred).
Route::post('/ingest', [IngestController::class, 'receive'])
    ->name('api.ingest.receive.bearer')
    ->middleware('throttle:60,1');

// Heartbeat pings — dead-man switches for scheduled work running outside Up
// (wp-cron, nightly rebuilds, sitemap regeneration).
//
// GET as well as POST, and the token accepted in the path: the realistic caller
// is a trailing `curl` on a crontab line, and a shape that cannot be expressed
// there would leave the feature unused on exactly the tasks it exists for. The
// token can only record a ping, so the exposure is bounded to a forged
// all-clear.
//
// Throttle is generous relative to the nominal cadence (minutes to days between
// pings) while still bounding a looping caller.
Route::match(['get', 'post'], '/heartbeat/{token}', [HeartbeatController::class, 'ping'])
    ->name('api.heartbeat.ping.token')
    ->middleware('throttle:60,1');

Route::match(['get', 'post'], '/heartbeat', [HeartbeatController::class, 'ping'])
    ->name('api.heartbeat.ping')
    ->middleware('throttle:60,1');

// Server metrics ingestion — push model (self-hosted agent).
// Auth: Authorization: Bearer <Server.ingest_token>. Self-hosted alternative to
// the Dokploy pull collector (Dokploy server monitoring is Cloud-only).
// Throttle is tight: nominal cadence is 1 push / 5 min, so 12/min leaves ample
// headroom for retries while a looping agent or stolen token cannot flood the
// metrics table or poison the sustained-CPU window.
Route::post('/servers/metrics', [ServerMetricController::class, 'store'])
    ->name('api.servers.metrics')
    ->middleware('throttle:12,1');

// Authenticated API routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/search', SearchApiController::class)->name('api.search');
    Route::apiResource('monitors', MonitorApiController::class)->names('api.monitors');
    Route::post('/monitors/{monitor}/pause', [MonitorApiController::class, 'pause'])->name('api.monitors.pause');
    Route::post('/monitors/{monitor}/resume', [MonitorApiController::class, 'resume'])->name('api.monitors.resume');
    Route::get('/monitors/{monitor}/checks', [MonitorApiController::class, 'checks'])->name('api.monitors.checks');

    Route::apiResource('notification-channels', NotificationChannelApiController::class)->names('api.notification-channels');

    Route::apiResource('status-pages', StatusPageApiController::class)->names('api.status-pages');

    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::apiResource('ingest-sources', IngestSourceApiController::class)->names('api.ingest-sources');
    Route::post('/ingest-sources/{ingestSource}/rotate-token', [IngestSourceApiController::class, 'rotateToken'])->name('api.ingest-sources.rotate-token');
    Route::get('/ingest-sources/{ingestSource}/events', [IngestSourceApiController::class, 'events'])->name('api.ingest-sources.events');

    Route::apiResource('sites', SiteApiController::class)->names('api.sites');

    // On-demand KPI collection (TTFB + GSC + GA4 per site) instead of waiting for 03:00.
    Route::post('/kpi/collect', [KpiCollectionApiController::class, 'collect'])->name('api.kpi.collect');

    // On-demand insight detection (striking-distance, content-decay, broken pages,
    // affiliate leaks) instead of waiting for 04:00. Run after a KPI collection.
    Route::post('/insights/dispatch', [InsightDispatchApiController::class, 'dispatch'])->name('api.insights.dispatch');

    // External insight ingestion — e.g. the /monitor Claude Code skill pushes
    // pre-computed findings (ssl_expiry, domain_expiry, uptime_incident …)
    // directly into Up. source is forced to 'monitor' server-side.
    Route::post('/insights', [InsightIngestionController::class, 'store'])->name('api.insights.store');

    // On-demand monitor↔site linking (sets monitor.site_id by domain) instead of
    // waiting for the 02:45 scheduled run. Run after adding monitors or sites.
    Route::post('/sites/link-monitors', [LinkMonitorsApiController::class, 'link'])->name('api.sites.link-monitors');
});
