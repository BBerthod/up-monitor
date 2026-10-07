<?php

namespace App\Notifications;

use App\Models\WarmSite;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WarmSiteDisabledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WarmSite $warmSite,
        public int $consecutiveFailures,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Cache Warming Auto-Disabled: {$this->warmSite->name}")
            ->error()
            ->line("The warming site **{$this->warmSite->domain}** has been automatically disabled after {$this->consecutiveFailures} consecutive failed runs.")
            ->line('No further warming notifications will be sent for this site.')
            ->action('Re-enable Site', url("/warming/{$this->warmSite->id}"))
            ->line('Please investigate the issue and re-enable the site once it is resolved.');
    }
}
