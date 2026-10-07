<?php

namespace App\Policies;

use App\Models\MonitorIncident;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MonitorIncidentPolicy
{
    use HandlesAuthorization;

    public function update(User $user, MonitorIncident $incident): bool
    {
        return $user->team_id === $incident->monitor->team_id;
    }
}
