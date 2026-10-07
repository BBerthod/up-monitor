<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SitePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->team_id !== null;
    }

    public function view(User $user, Site $site): bool
    {
        return $user->team_id === $site->team_id;
    }

    public function create(User $user): bool
    {
        return $user->team_id !== null;
    }

    public function update(User $user, Site $site): bool
    {
        return $user->team_id === $site->team_id;
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->team_id === $site->team_id;
    }
}
