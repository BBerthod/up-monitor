<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public array $digest,
        public string $teamName
    ) {}

    public function envelope(): Envelope
    {
        $start = $this->digest['period_start']->format('M j');
        $end = $this->digest['period_end']->format('M j');

        return new Envelope(
            subject: "[Up] Weekly Digest — {$this->teamName} — {$start} to {$end}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.digest',
            with: [
                'digest' => $this->digest,
                'teamName' => $this->teamName,
            ],
        );
    }
}
