<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReportFrequency;
use App\Mail\SiteReportMail;
use App\Models\Site;
use App\Services\SiteReportPdf;
use App\Services\SiteReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Manual send/preview of a Site's periodic report — used to test the pipeline
 * without waiting for the scheduler, or to write a PDF preview to disk.
 */
class SendSiteReportCommand extends Command
{
    protected $signature = 'sites:report
                            {site : Site ID or alias}
                            {--frequency=weekly : Report frequency (weekly|monthly)}
                            {--to=* : Recipient email(s), overrides the site\'s configured recipients}
                            {--pdf= : Write the PDF to this path instead of mailing the report}';

    protected $description = 'Generate and send (or preview) a periodic performance report for a Site.';

    public function handle(SiteReportService $reportService, SiteReportPdf $pdf): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));

        if ($site === null) {
            $this->error('Site not found.');

            return self::FAILURE;
        }

        $frequencyOption = (string) $this->option('frequency');
        $frequency = ReportFrequency::tryFrom($frequencyOption);

        if ($frequency === null || $frequency === ReportFrequency::NONE) {
            $this->error("Invalid --frequency: {$frequencyOption} (expected weekly|monthly).");

            return self::FAILURE;
        }

        $report = $reportService->generate($site, $frequency);

        $pdfPath = $this->option('pdf');
        if (is_string($pdfPath) && $pdfPath !== '') {
            file_put_contents($pdfPath, $pdf->render($report));
            $this->info("PDF written to {$pdfPath}");

            return self::SUCCESS;
        }

        $recipients = $this->option('to');
        if (! is_array($recipients) || $recipients === []) {
            $recipients = is_array($site->report_recipients) && $site->report_recipients !== []
                ? $site->report_recipients
                : config('reports.default_recipients', []);
        }

        if ($recipients === []) {
            $this->error('No recipients: pass --to, configure report_recipients on the site, or set SITE_REPORT_RECIPIENTS.');

            return self::FAILURE;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new SiteReportMail($report));
        }

        $this->info('Report sent to: '.implode(', ', $recipients));

        return self::SUCCESS;
    }

    private function resolveSite(string $identifier): ?Site
    {
        if (ctype_digit($identifier)) {
            return Site::withoutGlobalScopes()->find((int) $identifier);
        }

        return Site::withoutGlobalScopes()->where('alias', $identifier)->first();
    }
}
