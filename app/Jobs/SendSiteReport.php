<?php

namespace App\Jobs;

use App\Enums\ReportFrequency;
use App\Mail\SiteReportMail;
use App\Models\Site;
use App\Services\SiteReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendSiteReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public int $siteId,
        public string $frequency,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(SiteReportService $reportService): void
    {
        $site = Site::withoutGlobalScopes()->find($this->siteId);

        if ($site === null) {
            Log::warning('SendSiteReport: site not found', ['site_id' => $this->siteId]);

            return;
        }

        $recipients = $this->resolveRecipients($site);

        if ($recipients === []) {
            Log::info('SendSiteReport: no recipients configured, skipping', ['site_id' => $site->id]);

            return;
        }

        $frequency = ReportFrequency::from($this->frequency);
        $report = $reportService->generate($site, $frequency);

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new SiteReportMail($report));
        }

        $site->update(['last_report_sent_at' => now()]);
    }

    /**
     * @return list<string>
     */
    private function resolveRecipients(Site $site): array
    {
        $recipients = $site->report_recipients;

        if (is_array($recipients) && $recipients !== []) {
            return array_values($recipients);
        }

        return config('reports.default_recipients', []);
    }

    public function failed(Throwable $e): void
    {
        Log::error('SendSiteReport job failed', [
            'site_id' => $this->siteId,
            'frequency' => $this->frequency,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
