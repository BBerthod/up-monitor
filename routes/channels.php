<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('team.{teamId}', function ($user, int $teamId) {
    return $user->team_id === $teamId;
});
