<?php

namespace App\Notifications;

use App\Models\DeployEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeployPassedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public DeployEvent $deployEvent) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $commit = $this->deployEvent->commit_sha
            ? " (commit: {$this->deployEvent->commit_sha})"
            : '';

        return (new MailMessage)
            ->subject("Deploy passed smoke tests: {$this->deployEvent->application_name}")
            ->line("All smoke tests passed for **{$this->deployEvent->application_name}**{$commit}.")
            ->line('The application is healthy after the latest deployment.')
            ->line("Deployed at: {$this->deployEvent->deployed_at->format('Y-m-d H:i:s')} UTC");
    }
}
