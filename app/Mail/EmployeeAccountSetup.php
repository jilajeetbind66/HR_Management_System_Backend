<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeAccountSetup extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $loginEmail,
        public readonly string $employeeCode,
        public readonly string $role,
        public readonly string $setupUrl,
        public readonly int $expiresInMinutes
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Set up your Pinweb HRMS account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-account-setup',
            with: [
                'employeeName' => $this->employeeName,
                'loginEmail' => $this->loginEmail,
                'employeeCode' => $this->employeeCode,
                'role' => $this->role,
                'setupUrl' => $this->setupUrl,
                'expiresInMinutes' => $this->expiresInMinutes,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
