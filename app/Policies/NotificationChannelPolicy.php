<?php

namespace App\Policies;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class NotificationChannelPolicy
{
    use HandlesAuthorization;

    public function view(User $user, NotificationChannel $channel): bool
    {
        return $user->team_id === $channel->team_id;
    }

    public function update(User $user, NotificationChannel $channel): bool
    {
        return $user->team_id === $channel->team_id;
    }

    public function delete(User $user, NotificationChannel $channel): bool
    {
        return $user->team_id === $channel->team_id;
    }

    public function test(User $user, NotificationChannel $channel): bool
    {
        return $user->team_id === $channel->team_id;
    }
}
