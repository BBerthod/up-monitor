<?php

namespace App\Policies;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StatusPagePolicy
{
    use HandlesAuthorization;

    public function view(User $user, StatusPage $statusPage): bool
    {
        return $user->team_id === $statusPage->team_id;
    }

    public function update(User $user, StatusPage $statusPage): bool
    {
        return $user->team_id === $statusPage->team_id;
    }

    public function delete(User $user, StatusPage $statusPage): bool
    {
        return $user->team_id === $statusPage->team_id;
    }
}
