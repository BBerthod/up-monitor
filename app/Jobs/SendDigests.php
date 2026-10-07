<?php

namespace App\Jobs;

use App\Mail\DigestMail;
use App\Models\Team;
use App\Services\DigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDigests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 60, 120];

    public function __construct()
    {
        // Sent alongside weekly reports; shares the notifications queue so both
        // jobs are processed by the same worker pool with appropriate priority.
        $this->onQueue('notifications');
    }

    public function handle(DigestService $digestService): void
    {
        // Chunk to avoid loading every team into memory at once; 50 is the same
        // batch size used by SendWeeklyReports for consistency.
        Team::with('users')->whereHas('monitors', fn ($q) => $q->where('is_active', true))->chunk(50, function ($teams) use ($digestService) {
            foreach ($teams as $team) {
                try {
                    $digest = $digestService->generateForTeam($team);

                    $team->users
                        ->filter(fn ($user) => $user->digest_enabled === true)
                        ->each(fn ($user) => Mail::to($user->email)->send(new DigestMail($digest, $team->name)));
                } catch (Throwable $e) {
                    // Isolate per-team failures so one broken team doesn't abort
                    // the rest of the portfolio.
                    Log::error('Digest failed for team', [
                        'team_id' => $team->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendDigests job failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
