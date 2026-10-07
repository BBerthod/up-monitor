<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarmSite;
use Illuminate\Auth\Access\HandlesAuthorization;

class WarmSitePolicy
{
    use HandlesAuthorization;

    public function view(User $user, WarmSite $warmSite): bool
    {
        return $user->team_id === $warmSite->team_id;
    }

    public function update(User $user, WarmSite $warmSite): bool
    {
        return $user->team_id === $warmSite->team_id;
    }

    public function delete(User $user, WarmSite $warmSite): bool
    {
        return $user->team_id === $warmSite->team_id;
    }

    public function warmNow(User $user, WarmSite $warmSite): bool
    {
        return $user->team_id === $warmSite->team_id;
    }
}
