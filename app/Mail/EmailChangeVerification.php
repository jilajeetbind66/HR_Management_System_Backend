<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeVerification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $verificationUrl,
        public readonly string $expiresAt
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your HRMS login email');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-change-verification',
            with: [
                'verificationUrl' => $this->verificationUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
