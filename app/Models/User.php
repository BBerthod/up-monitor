<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'team_id', 'role', 'weekly_report_enabled', 'digest_enabled'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'weekly_report_enabled' => 'boolean',
            'digest_enabled' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role->isAtLeast(UserRole::ADMIN);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }

    public function isAtLeast(UserRole $role): bool
    {
        return $this->role->isAtLeast($role);
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function scopeAdmin($query)
    {
        return $query->whereIn('role', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value]);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
