<?php

namespace App\Jobs\Notifications;

use App\Mail\MonitorAlertMail;
use Illuminate\Support\Facades\Mail;

class SendEmailNotification extends BaseNotificationJob
{
    protected function send(): void
    {
        $payload = $this->buildPayload();

        Mail::to($this->channel->settings['recipients'])
            ->send(new MonitorAlertMail($this->event, $payload));
    }
}
