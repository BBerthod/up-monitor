<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmokeTestConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'application_name',
        'tests',
        'is_active',
    ];

    protected $casts = [
        'tests' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
