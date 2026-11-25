<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $emailChanged = false;

        DB::transaction(function () use ($request, $user, &$emailChanged): void {
            $user->fill($request->validated());
            $emailChanged = $user->isDirty('email');

            if ($emailChanged) {
                $user->email_verified_at = null;
                $user->unblocked_at = now();
            }

            $user->save();

            if ($emailChanged) {
                $user->queueEmailVerification(true);
            }
        });

        if ($emailChanged) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Redirect::route('login')->with(
                'status',
                'Подтвердите новый адрес электронной почты по ссылке в письме. На подтверждение есть 7 дней.'
            );
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
