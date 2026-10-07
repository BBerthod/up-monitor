<?php

namespace App\Notifications;

use App\Models\DeployEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeployRollbackFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DeployEvent $deployEvent,
        public string $rollbackError,
    ) {}

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
            ->subject("CRITICAL: rollback FAILED for {$this->deployEvent->application_name} — MANUAL ACTION REQUIRED")
            ->error()
            ->line("**CRITICAL**: The automatic rollback for **{$this->deployEvent->application_name}**{$commit} has FAILED.")
            ->line('The application is running a broken deployment and the rollback could not be completed automatically.')
            ->line('**Immediate manual intervention is required.**')
            ->line("Rollback error: {$this->rollbackError}")
            ->line("Application ID: {$this->deployEvent->application_id}")
            ->line("Deployed at: {$this->deployEvent->deployed_at->format('Y-m-d H:i:s')} UTC");
    }
}
