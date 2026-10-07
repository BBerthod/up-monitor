<?php

namespace App\Mail;

use App\Enums\ReportFrequency;
use App\Services\SiteReportPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SiteReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $report,
    ) {}

    public function envelope(): Envelope
    {
        $frequency = ReportFrequency::from($this->report['frequency']);
        $frequencyLabel = $frequency === ReportFrequency::MONTHLY ? 'mensuel' : 'hebdomadaire';
        $siteLabel = $this->report['site']['alias'] ?? $this->report['site']['domain'];

        return new Envelope(
            subject: "Rapport {$frequencyLabel} — {$siteLabel} — {$this->report['period']['label']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.site-report',
            with: ['report' => $this->report],
        );
    }

    public function attachments(): array
    {
        $pdf = app(SiteReportPdf::class)->render($this->report);

        $date = $this->report['period']['end']->format('Y-m-d');
        $slug = $this->report['site']['alias'] ?? (string) $this->report['site']['id'];

        return [
            Attachment::fromData(fn () => $pdf, "rapport-{$slug}-{$date}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
