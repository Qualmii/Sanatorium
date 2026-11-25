<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;

class UserObserver
{
    public function updating(User $user): void
    {
        if ($user->isDirty('is_blocked') && ! $user->is_blocked && ! $user->hasVerifiedEmail()) {
            $user->unblocked_at = now();
        }
    }

    public function updated(User $user): void
    {
        if (
            $user->wasChanged('is_blocked')
            && ! $user->is_blocked
            && ! $user->hasVerifiedEmail()
        ) {
            $user->queueEmailVerification();
        }
    }
}
