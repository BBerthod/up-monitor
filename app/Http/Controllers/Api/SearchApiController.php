<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Site;
use App\Models\StatusPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchApiController extends Controller
{
    private const GROUP_LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->string('q')->trim();

        if ($query->length() < 2) {
            return response()->json([
                'monitors' => [],
                'incidents' => [],
                'notification_channels' => [],
                'status_pages' => [],
                'sites' => [],
                'servers' => [],
            ]);
        }

        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], (string) $query);
        $searchTerm = "%{$escaped}%";

        $monitors = Monitor::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $searchTerm)
                ->orWhere('url', 'like', $searchTerm)
            )
            ->with(['checks' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn ($monitor) => [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'url' => $monitor->url,
                'is_active' => $monitor->is_active,
                'status' => $monitor->checks->first()?->status?->value,
            ]);

        $incidents = MonitorIncident::query()
            ->whereHas('monitor')
            ->where(fn ($q) => $q
                ->whereHas('monitor', fn ($mq) => $mq
                    ->where('name', 'like', $searchTerm)
                    ->orWhere('url', 'like', $searchTerm)
                )
                ->orWhere('cause', 'like', $searchTerm)
            )
            ->with('monitor')
            ->latest('started_at')
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn ($incident) => [
                'id' => $incident->id,
                'monitor_id' => $incident->monitor_id,
                'monitor_name' => $incident->monitor->name,
                'cause' => $incident->cause?->value,
                'started_at' => $incident->started_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ]);

        $notificationChannels = NotificationChannel::query()
            ->where('name', 'like', $searchTerm)
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn ($channel) => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->type?->value,
                'is_active' => $channel->is_active,
            ]);

        $statusPages = StatusPage::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $searchTerm)
                ->orWhere('slug', 'like', $searchTerm)
            )
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn ($page) => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'is_active' => $page->is_active,
            ]);

        // Sites — search on alias and primary_domain (team-scoped via global scope).
        $sites = Site::query()
            ->where(fn ($q) => $q
                ->where('alias', 'like', $searchTerm)
                ->orWhere('primary_domain', 'like', $searchTerm)
            )
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->alias ?: $site->resolvedPrimaryDomain(),
                'domain' => $site->resolvedPrimaryDomain(),
                'url' => route('sites.show', $site->id),
            ]);

        // Servers — search on name and dokploy_server_id (team-scoped via global scope).
        $servers = Server::query()
            ->where(fn ($q) => $q
                ->where('name', 'like', $searchTerm)
                ->orWhere('dokploy_server_id', 'like', $searchTerm)
            )
            ->limit(self::GROUP_LIMIT)
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'dokploy_server_id' => $server->dokploy_server_id,
                'is_active' => $server->is_active,
                'url' => route('servers.show', $server->id),
            ]);

        return response()->json([
            'monitors' => $monitors,
            'incidents' => $incidents,
            'notification_channels' => $notificationChannels,
            'status_pages' => $statusPages,
            'sites' => $sites,
            'servers' => $servers,
        ]);
    }
}
