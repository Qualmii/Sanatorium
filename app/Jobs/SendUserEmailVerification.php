<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Notifications\UserEmailVerificationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendUserEmailVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $userId,
        public string $email,
        public string $verificationUrl,
        public string $verificationStartedAt,
        public bool $emailChanged,
    ) {
        $this->onConnection('database')->onQueue('verification-mail');
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if (
            ! $user
            || $user->is_blocked
            || $user->hasVerifiedEmail()
            || $user->email !== $this->email
            || ! $user->unblocked_at
            || $user->unblocked_at->toDateTimeString() !== $this->verificationStartedAt
            || $user->unblocked_at->copy()->addDays(7)->lessThanOrEqualTo(now())
        ) {
            return;
        }

        Mail::send((new UserEmailVerificationNotification($this->emailChanged))
            ->toMailWithUrl($user, $this->verificationUrl));
    }
}
