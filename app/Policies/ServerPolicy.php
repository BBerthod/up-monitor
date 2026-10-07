<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ServerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->team_id !== null;
    }

    public function view(User $user, Server $server): bool
    {
        return $user->team_id === $server->team_id;
    }

    public function create(User $user): bool
    {
        return $user->team_id !== null;
    }

    public function update(User $user, Server $server): bool
    {
        return $user->team_id === $server->team_id;
    }

    public function delete(User $user, Server $server): bool
    {
        return $user->team_id === $server->team_id;
    }
}
