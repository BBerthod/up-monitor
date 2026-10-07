<?php

namespace App\Policies;

use App\Models\Insight;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InsightPolicy
{
    use HandlesAuthorization;

    public function update(User $user, Insight $insight): bool
    {
        return $user->team_id === $insight->team_id;
    }
}
