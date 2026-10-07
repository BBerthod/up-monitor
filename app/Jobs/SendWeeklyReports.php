<?php

namespace App\Jobs;

use App\Mail\WeeklyReportMail;
use App\Models\Team;
use App\Services\WeeklyReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendWeeklyReports implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 60, 120];

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(WeeklyReportService $weeklyReportService): void
    {
        Team::with('users')->whereHas('monitors', fn ($q) => $q->where('is_active', true))->chunk(50, function ($teams) use ($weeklyReportService) {
            foreach ($teams as $team) {
                try {
                    $report = $weeklyReportService->generate($team);

                    $team->users
                        ->filter(fn ($user) => $user->weekly_report_enabled === true)
                        ->each(fn ($user) => Mail::to($user->email)->send(new WeeklyReportMail($report, $team->name)));
                } catch (Throwable $e) {
                    Log::error('Weekly report failed for team', ['team_id' => $team->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendWeeklyReports job failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
