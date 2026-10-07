<?php

namespace App\Policies;

use App\Models\IngestSource;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class IngestSourcePolicy
{
    use HandlesAuthorization;

    public function view(User $user, IngestSource $source): bool
    {
        return $user->team_id === $source->team_id;
    }

    public function update(User $user, IngestSource $source): bool
    {
        return $user->team_id === $source->team_id;
    }

    public function delete(User $user, IngestSource $source): bool
    {
        return $user->team_id === $source->team_id;
    }
}
