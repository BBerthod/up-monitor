<?php

namespace App\Policies;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MonitorPolicy
{
    use HandlesAuthorization;

    public function view(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function update(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function delete(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function pause(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function resume(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function lighthouse(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }

    public function purge(User $user, Monitor $monitor): bool
    {
        return $user->team_id === $monitor->team_id;
    }
}
