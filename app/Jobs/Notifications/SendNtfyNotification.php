<?php

namespace App\Jobs\Notifications;

use App\Support\NtfyPublisher;

class SendNtfyNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $settings = $this->channel->settings ?? [];
        $isDown = $this->event === 'down';

        NtfyPublisher::publish(
            $settings,
            '[Up] '.$this->monitor->name.' is '.($isDown ? 'Down' : 'Up'),
            "URL: {$this->monitor->url} - Cause: ".ucfirst(str_replace('_', ' ', $this->incident->cause->value)),
            $isDown ? NtfyPublisher::downPriority($settings) : NtfyPublisher::PRIORITY_DEFAULT,
            [$isDown ? 'rotating_light' : 'white_check_mark'],
            config('app.url')."/monitors/{$this->monitor->id}",
        );
    }
}
