<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class ContactHrMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $employeeCode,
        public readonly string $messageText,
        public readonly ?string $ipAddress = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New HRMS Access Request: ' . $this->name,
            replyTo: [
                new Address($this->email, $this->name),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-hr',
            with: [
                'name' => $this->name,
                'email' => $this->email,
                'employeeCode' => $this->employeeCode ?: 'N/A (New Requester)',
                'messageText' => $this->messageText,
                'ipAddress' => $this->ipAddress,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Mailin-ClickTrack' => '0',
                'X-Brevo-Track' => '0',
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
