<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        $newlyVerified = false;
        $user = DB::transaction(function () use ($id, $hash, &$newlyVerified): ?User {
            $user = User::query()->lockForUpdate()->findOrFail($id);

            abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
            abort_if($user->is_blocked || ! $user->unblocked_at, 403);

            if ($user->hasVerifiedEmail()) {
                return $user;
            }

            if ($user->unblocked_at->copy()->addDays(7)->lessThanOrEqualTo(now())) {
                $user->sections()->detach();
                $user->delete();

                return null;
            }

            $newlyVerified = $user->markEmailAsVerified();

            return $user;
        });

        if (! $user) {
            return redirect('/')->with(
                'error',
                'Срок подтверждения истёк, аккаунт удалён. Для входа необходимо зарегистрироваться повторно.'
            );
        }

        if ($newlyVerified) {
            event(new Verified($user));

            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->intended(RouteServiceProvider::HOME.'?verified=1');
    }
}
