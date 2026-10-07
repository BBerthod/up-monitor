<?php

namespace App\Models;

use App\Enums\FunctionalCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FunctionalCheckResult extends Model
{
    protected $fillable = [
        'functional_check_id',
        'status',
        'duration_ms',
        'details',
        'error_message',
        'checked_at',
    ];

    protected $casts = [
        'status' => FunctionalCheckStatus::class,
        'details' => 'array',
        'checked_at' => 'datetime',
    ];

    public function functionalCheck(): BelongsTo
    {
        return $this->belongsTo(FunctionalCheck::class);
    }
}
