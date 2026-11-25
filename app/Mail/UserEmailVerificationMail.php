<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserEmailVerificationMail extends Mailable
{
    use SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $verificationUrl,
        public bool $emailChanged = false,
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Подтверждение адреса электронной почты',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.user-email-verification',
            with: [
                'user' => $this->user,
                'verificationUrl' => $this->verificationUrl,
                'emailChanged' => $this->emailChanged,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
