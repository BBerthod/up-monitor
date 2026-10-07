<?php

namespace App\Http\Controllers;

use App\Enums\WarmRunStatus;
use App\Enums\WarmSiteMode;
use App\Http\Requests\StoreWarmSiteRequest;
use App\Http\Requests\UpdateWarmSiteRequest;
use App\Http\Traits\FiltersBySiteScope;
use App\Jobs\RunWarmSite;
use App\Models\Monitor;
use App\Models\WarmRun;
use App\Models\WarmSite;
use App\Services\NotificationService;
use App\Services\WarmingService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class WarmSiteController extends Controller
{
    use FiltersBySiteScope;

    private function frequencies(): array
    {
        return [
            ['value' => 15, 'label' => '15 minutes'],
            ['value' => 30, 'label' => '30 minutes'],
            ['value' => 60, 'label' => '1 hour'],
            ['value' => 120, 'label' => '2 hours'],
            ['value' => 360, 'label' => '6 hours'],
            ['value' => 720, 'label' => '12 hours'],
            ['value' => 1440, 'label' => '24 hours'],
        ];
    }

    private function modes(): array
    {
        return collect(WarmSiteMode::cases())
            ->map(fn ($m) => ['value' => $m->value, 'label' => $m->label()])
            ->all();
    }

    public function index(): Response
    {
        $scope = $this->currentSiteScope();

        $query = WarmSite::with(['latestCompletedRun', 'monitor:id,name'])
            ->orderBy('name');

        // Apply site-scope lens via the linked monitor's site_id.
        if ($scope->isSite() && $scope->site !== null) {
            $siteId = $scope->site->id;
            $query->whereHas('monitor', fn ($q) => $q->where('site_id', $siteId));
        } elseif ($scope->isUnassigned()) {
            // Unassigned: warm sites whose monitor has no site, or warm sites with no monitor.
            $query->where(function ($q): void {
                $q->whereNull('monitor_id')
                    ->orWhereHas('monitor', fn ($mq) => $mq->whereNull('site_id'));
            });
        }

        $warmSites = $query->get()
            ->map(fn ($site) => [
                'id' => $site->id,
                'name' => $site->name,
                'domain' => $site->domain,
                'mode' => $site->mode->value,
                'mode_label' => $site->mode->label(),
                'frequency_minutes' => $site->frequency_minutes,
                'is_active' => $site->is_active,
                'last_warmed_at' => $site->last_warmed_at?->toIso8601String(),
                'monitor_id' => $site->monitor_id,
                'monitor_name' => $site->monitor?->name,
                'last_run' => $site->latestCompletedRun ? [
                    'urls_total' => $site->latestCompletedRun->urls_total,
                    'urls_hit' => $site->latestCompletedRun->urls_hit,
                    'urls_miss' => $site->latestCompletedRun->urls_miss,
                    'urls_error' => $site->latestCompletedRun->urls_error,
                    'hit_ratio' => $site->latestCompletedRun->hit_ratio,
                    'avg_response_ms' => $site->latestCompletedRun->avg_response_ms,
                    'status' => $site->latestCompletedRun->status->value,
                ] : null,
            ]);

        return Inertia::render('CacheWarming/Index', [
            'warmSites' => $warmSites,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CacheWarming/Create', [
            'frequencies' => $this->frequencies(),
            'modes' => $this->modes(),
            'monitors' => Monitor::select('id', 'name', 'url')->orderBy('name')->get(),
            'blockedHeaders' => WarmingService::BLOCKED_HEADERS,
        ]);
    }

    public function store(StoreWarmSiteRequest $request): RedirectResponse
    {
        $warming = WarmSite::create($request->validated());

        return to_route('warming.show', $warming);
    }

    public function show(WarmSite $warming): Response
    {
        $this->authorize('view', $warming);
        $warming->load('monitor:id,name,url');

        $recentRuns = $warming->warmRuns()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($run) => [
                'id' => $run->id,
                'urls_total' => $run->urls_total,
                'urls_hit' => $run->urls_hit,
                'urls_miss' => $run->urls_miss,
                'urls_error' => $run->urls_error,
                'hit_ratio' => $run->hit_ratio,
                'avg_response_ms' => $run->avg_response_ms,
                'status' => $run->status->value,
                'error_message' => $run->error_message,
                'duration_seconds' => $run->duration_seconds,
                'started_at' => $run->started_at->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ]);

        $chartData = $warming->warmRuns()
            ->where('status', 'completed')
            ->orderBy('started_at')
            ->limit(50)
            ->get(['urls_total', 'urls_hit', 'avg_response_ms', 'started_at'])
            ->map(fn ($run) => [
                'date' => $run->started_at->toIso8601String(),
                'hit_ratio' => $run->urls_total > 0
                    ? round(($run->urls_hit / $run->urls_total) * 100, 1)
                    : 0,
                'avg_ms' => $run->avg_response_ms,
            ]);

        // Aggregated stats from last 24h completed runs
        $stats24h = $warming->warmRuns()
            ->where('status', 'completed')
            ->where('started_at', '>=', now()->subDay())
            ->selectRaw('
                COUNT(*) as runs_completed,
                SUM(urls_total) as total_urls,
                SUM(urls_hit) as total_hits,
                SUM(urls_miss) as total_misses,
                SUM(urls_error) as total_errors,
                AVG(avg_response_ms) as avg_response_ms
            ')
            ->first();

        $totalRuns24h = $warming->warmRuns()
            ->where('started_at', '>=', now()->subDay())
            ->count();

        $lastSuccessfulRun = $warming->warmRuns()
            ->where('status', 'completed')
            ->orderByDesc('started_at')
            ->first();

        return Inertia::render('CacheWarming/Show', [
            'warmSite' => [
                'id' => $warming->id,
                'name' => $warming->name,
                'domain' => $warming->domain,
                'mode' => $warming->mode->value,
                'mode_label' => $warming->mode->label(),
                'sitemap_url' => $warming->sitemap_url,
                'urls' => $warming->urls,
                'frequency_minutes' => $warming->frequency_minutes,
                'max_urls' => $warming->max_urls,
                'custom_headers' => $warming->custom_headers,
                'timeout_seconds' => $warming->timeout_seconds,
                'is_active' => $warming->is_active,
                'last_warmed_at' => $warming->last_warmed_at?->toIso8601String(),
                'monitor' => $warming->monitor ? [
                    'id' => $warming->monitor->id,
                    'name' => $warming->monitor->name,
                    'url' => $warming->monitor->url,
                ] : null,
            ],
            'recentRuns' => $recentRuns,
            'chartData' => $chartData,
            'stats24h' => [
                'runs_completed' => (int) ($stats24h->runs_completed ?? 0),
                'runs_total' => $totalRuns24h,
                'total_urls' => (int) ($stats24h->total_urls ?? 0),
                'hit_ratio' => ($stats24h->total_urls ?? 0) > 0
                    ? (float) round(($stats24h->total_hits / $stats24h->total_urls) * 100, 1)
                    : null,
                'avg_response_ms' => (int) ($stats24h->avg_response_ms ?? 0),
                'total_errors' => (int) ($stats24h->total_errors ?? 0),
            ],
            'lastSuccessfulRun' => $lastSuccessfulRun ? [
                'started_at' => $lastSuccessfulRun->started_at->toIso8601String(),
                'urls_total' => $lastSuccessfulRun->urls_total,
                'hit_ratio' => $lastSuccessfulRun->hit_ratio,
            ] : null,
        ]);
    }

    public function edit(WarmSite $warming): Response
    {
        $this->authorize('update', $warming);

        return Inertia::render('CacheWarming/Edit', [
            'warmSite' => [
                'id' => $warming->id,
                'name' => $warming->name,
                'domain' => $warming->domain,
                'mode' => $warming->mode->value,
                'sitemap_url' => $warming->sitemap_url,
                'urls' => $warming->urls,
                'frequency_minutes' => $warming->frequency_minutes,
                'max_urls' => $warming->max_urls,
                'custom_headers' => $warming->custom_headers,
                'timeout_seconds' => $warming->timeout_seconds,
                'is_active' => $warming->is_active,
                'monitor_id' => $warming->monitor_id,
            ],
            'frequencies' => $this->frequencies(),
            'modes' => $this->modes(),
            'monitors' => Monitor::select('id', 'name', 'url')->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateWarmSiteRequest $request, WarmSite $warming): RedirectResponse
    {
        $this->authorize('update', $warming);

        $wasInactive = ! $warming->is_active;

        $warming->update($request->validated());

        // Manual re-enable after an auto-disable: acknowledge the WARMING_DISABLED
        // insight immediately rather than waiting for the next successful run
        // (which may be hours away depending on frequency_minutes).
        if ($wasInactive && $warming->is_active) {
            app(NotificationService::class)->resolveWarmingDisabled($warming);
        }

        return to_route('warming.show', $warming);
    }

    public function destroy(WarmSite $warming): RedirectResponse
    {
        $this->authorize('delete', $warming);

        $warming->delete();

        return to_route('warming.index');
    }

    public function runDetail(WarmSite $warming, WarmRun $warmRun): Response
    {
        $this->authorize('view', $warming);

        abort_unless($warmRun->warm_site_id === $warming->id, 404);

        $urlResults = $warmRun->urls()
            ->orderBy('id')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'url' => $u->url,
                'status_code' => $u->status_code,
                'cache_status' => $u->cache_status,
                'response_time_ms' => $u->response_time_ms,
                'error_message' => $u->error_message,
            ]);

        return Inertia::render('CacheWarming/RunDetail', [
            'warmSite' => [
                'id' => $warming->id,
                'name' => $warming->name,
                'domain' => $warming->domain,
            ],
            'warmRun' => [
                'id' => $warmRun->id,
                'urls_total' => $warmRun->urls_total,
                'urls_hit' => $warmRun->urls_hit,
                'urls_miss' => $warmRun->urls_miss,
                'urls_error' => $warmRun->urls_error,
                'hit_ratio' => $warmRun->hit_ratio,
                'avg_response_ms' => $warmRun->avg_response_ms,
                'status' => $warmRun->status->value,
                'error_message' => $warmRun->error_message,
                'duration_seconds' => $warmRun->duration_seconds,
                'started_at' => $warmRun->started_at->toIso8601String(),
                'completed_at' => $warmRun->completed_at?->toIso8601String(),
            ],
            'urlResults' => $urlResults,
        ]);
    }

    public function warmNow(WarmSite $warming): RedirectResponse
    {
        $this->authorize('warmNow', $warming);

        // Guard against orphaned/in-progress runs: if a run has been "running" for less than
        // 15 minutes it is still considered live (the lock is held, ShouldBeUnique would silently
        // drop the dispatched job).  Return honest feedback instead of a misleading success flash.
        $hasActiveRun = WarmRun::where('warm_site_id', $warming->id)
            ->where('status', WarmRunStatus::RUNNING)
            ->where('started_at', '>=', now()->subMinutes(15))
            ->exists();

        if ($hasActiveRun) {
            return back()->with('warning', 'A warm run is already in progress for this site.');
        }

        RunWarmSite::dispatch($warming);

        return back()->with('success', 'Cache warming queued successfully.');
    }
}
