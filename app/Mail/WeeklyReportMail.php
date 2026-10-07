<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $report,
        public string $teamName
    ) {}

    public function envelope(): Envelope
    {
        $start = $this->report['period_start']->format('M j');
        $end = $this->report['period_end']->format('M j');

        return new Envelope(
            subject: "[Up] Weekly Report — {$this->teamName} — {$start} to {$end}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.weekly-report',
            with: [
                'report' => $this->report,
                'teamName' => $this->teamName,
            ],
        );
    }
}
