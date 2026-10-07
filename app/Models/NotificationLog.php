<?php

namespace App\Models;

use App\Models\Traits\ScopedByMonitorTeam;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;
    use ScopedByMonitorTeam;

    public $timestamps = false;

    protected $fillable = [
        'notification_channel_id',
        'monitor_id',
        'monitor_incident_id',
        'event',
        'channel_type',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function notificationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class);
    }

    public function monitorIncident(): BelongsTo
    {
        return $this->belongsTo(MonitorIncident::class);
    }
}
