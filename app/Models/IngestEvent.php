<?php

namespace App\Models;

use App\Enums\IngestEventLevel;
use App\Enums\IngestEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_id',
        'type',
        'level',
        'message',
        'context',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => IngestEventType::class,
            'level' => IngestEventLevel::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(IngestSource::class, 'source_id');
    }

    public function shouldNotify(): bool
    {
        return $this->level->isAlert() || $this->type === IngestEventType::JOB_FAILED;
    }

    public function scopeAlert($query)
    {
        return $query->whereIn('level', [IngestEventLevel::CRITICAL->value, IngestEventLevel::EMERGENCY->value]);
    }
}
