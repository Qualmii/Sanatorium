<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        $user = $request->user();

        abort_if(
            $user->is_blocked || ! $user->unblocked_at || $user->unblocked_at->copy()->addDays(7)->lessThanOrEqualTo(now()),
            403
        );

        $user->queueEmailVerification();

        return back()->with('status', 'verification-link-sent');
    }
}
