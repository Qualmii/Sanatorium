<?php

namespace App\Notifications;

use App\Mail\UserEmailVerificationMail;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class UserEmailVerificationNotification extends Notification
{
    public function __construct(private bool $emailChanged = false)
    {
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): UserEmailVerificationMail
    {
        return $this->toMailWithUrl($notifiable, $this->verificationUrl($notifiable));
    }

    public function toMailWithUrl(User $notifiable, string $verificationUrl): UserEmailVerificationMail
    {
        return (new UserEmailVerificationMail($notifiable, $verificationUrl, $this->emailChanged))
            ->to($notifiable->email);
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(User $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $notifiable->unblocked_at->copy()->addDays(7),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
