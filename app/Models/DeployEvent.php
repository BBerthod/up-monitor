<?php

namespace App\Models;

use App\Enums\SmokeTestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeployEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'application_name',
        'commit_sha',
        'deployed_at',
        'smoke_test_status',
        'smoke_test_results',
        'rollback_triggered',
    ];

    protected $casts = [
        'deployed_at' => 'datetime',
        'smoke_test_status' => SmokeTestStatus::class,
        'smoke_test_results' => 'array',
        'rollback_triggered' => 'boolean',
    ];

    public function isPending(): bool
    {
        return $this->smoke_test_status === SmokeTestStatus::PENDING;
    }

    public function hasFailed(): bool
    {
        return $this->smoke_test_status === SmokeTestStatus::FAILED;
    }

    public function hasPassed(): bool
    {
        return $this->smoke_test_status === SmokeTestStatus::PASSED;
    }
}
