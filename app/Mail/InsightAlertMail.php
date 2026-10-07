<?php

namespace App\Mail;

use App\Models\Insight;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InsightAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Insight $insight,
        public readonly string $title,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Up Alert] {$this->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.insight-alert',
            with: [
                'insight' => $this->insight,
                'title' => $this->title,
            ],
        );
    }
}
