<?php

namespace App\Http\Controllers;

use App\Enums\ChannelType;
use App\Models\Monitor;
use App\Models\NotificationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationLogController extends Controller
{
    public function index(Request $request): \Inertia\Response
    {
        $query = NotificationLog::query()
            ->join('monitors', 'notification_logs.monitor_id', '=', 'monitors.id')
            ->leftJoin('notification_channels', 'notification_logs.notification_channel_id', '=', 'notification_channels.id')
            ->select([
                'notification_logs.*',
                'monitors.name as monitor_name',
                'notification_channels.name as channel_name',
            ]);

        if ($request->filled('channel_type')) {
            $query->where('notification_logs.channel_type', $request->input('channel_type'));
        }

        if ($request->filled('monitor_id')) {
            $query->where('notification_logs.monitor_id', $request->input('monitor_id'));
        }

        if ($request->filled('event')) {
            $query->where('notification_logs.event', $request->input('event'));
        }

        if ($request->filled('status')) {
            $query->where('notification_logs.status', $request->input('status'));
        }

        if ($request->filled('from')) {
            $query->where('notification_logs.sent_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('notification_logs.sent_at', '<=', $request->input('to').' 23:59:59');
        }

        $query->orderByDesc('notification_logs.sent_at');

        return Inertia::render('NotificationHistory/Index', [
            'logs' => $query->paginate(25)->withQueryString(),
            'monitors' => Monitor::select('id', 'name')->orderBy('name')->get(),
            'channelTypes' => collect(ChannelType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst($c->value)]),
            'filters' => $request->only(['channel_type', 'monitor_id', 'event', 'status', 'from', 'to']),
        ]);
    }
}
