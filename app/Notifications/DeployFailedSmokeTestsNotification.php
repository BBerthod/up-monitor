<?php

namespace App\Notifications;

use App\Models\DeployEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeployFailedSmokeTestsNotification extends Notification implements ShouldQueue
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

        $failedTests = collect($this->deployEvent->smoke_test_results ?? [])
            ->filter(fn ($r) => ! $r['passed'])
            ->values();

        $message = (new MailMessage)
            ->subject("Deploy FAILED smoke tests — rolling back: {$this->deployEvent->application_name}")
            ->error()
            ->line("Smoke tests FAILED for **{$this->deployEvent->application_name}**{$commit}.")
            ->line('Automatic rollback has been triggered.');

        foreach ($failedTests as $test) {
            $message->line("- `{$test['url']}`: {$test['error']}");
        }

        return $message->line("Deployed at: {$this->deployEvent->deployed_at->format('Y-m-d H:i:s')} UTC");
    }
}
