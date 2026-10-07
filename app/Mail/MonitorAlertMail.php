<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonitorAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $event,
        public array $payload
    ) {}

    public function envelope(): Envelope
    {
        $eventTitle = ucfirst($this->event);
        $monitorName = $this->payload['monitor']['name'];

        return new Envelope(
            subject: "Monitor {$eventTitle}: {$monitorName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.monitor-alert',
            with: [
                'event' => $this->event,
                'payload' => $this->payload,
            ],
        );
    }
}
