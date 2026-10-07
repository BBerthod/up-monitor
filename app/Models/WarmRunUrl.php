<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmRunUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'warm_run_id',
        'url',
        'status_code',
        'cache_status',
        'response_time_ms',
        'error_message',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
    ];

    public function warmRun(): BelongsTo
    {
        return $this->belongsTo(WarmRun::class);
    }
}
