<?php

namespace App\Notifications;

use App\Models\WarmRun;
use App\Models\WarmSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WarmRunFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WarmSite $warmSite,
        public WarmRun $warmRun,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Cache Warming Failed: {$this->warmSite->name}")
            ->line("The warming run for **{$this->warmSite->domain}** encountered errors.")
            ->line("Error: {$this->warmRun->error_message}")
            ->line("URLs processed: {$this->warmRun->urls_total} | Errors: {$this->warmRun->urls_error}")
            ->action('View Details', url("/warming/{$this->warmSite->id}"))
            ->line('This notification has a 1-hour cooldown per site.');
    }
}
