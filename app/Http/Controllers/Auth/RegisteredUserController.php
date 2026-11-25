<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'second_name' => ['string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+7[0-9]{10}$/', 'max:12'],
            'section' => ['required', 'integer', 'min:1', 'max:999'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): ?User {
            $existingEmailUser = User::query()
                ->where('email', $validated['email'])
                ->lockForUpdate()
                ->first();

            if ($existingEmailUser?->hasVerifiedEmail()) {
                return null;
            }

            if ($existingEmailUser && (
                $existingEmailUser->is_blocked
                || ! $existingEmailUser->unblocked_at
                || $existingEmailUser->unblocked_at->copy()->addDays(7)->isFuture()
            )) {
                throw ValidationException::withMessages([
                    'email' => $existingEmailUser->is_blocked
                        ? 'Ваша заявка уже зарегистрирована и ожидает одобрения администратора.'
                        : 'Ваша почта уже была зарегистрирована и вам направлено письмо для подтверждения почты. Пожалуйста, найдите его во входящих письмах и следуйте инструкциям',
                ]);
            }

            $existingPhoneUser = User::query()
                ->where('phone', $validated['phone'])
                ->lockForUpdate()
                ->first();

            if ($existingPhoneUser && $existingPhoneUser->isNot($existingEmailUser)) {
                throw ValidationException::withMessages([
                    'phone' => 'Этот номер телефона уже зарегистрирован.',
                ]);
            }

            if ($existingEmailUser) {
                DB::table('section_user')
                    ->where('user_id', $existingEmailUser->getKey())
                    ->delete();
                $existingEmailUser->delete();
            }

            $user = User::query()->create([
                'first_name' => $validated['first_name'],
                'second_name' => $validated['second_name'] ?? null,
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'is_blocked' => true,
            ]);

            $section = Section::query()->createOrFirst(['section' => $validated['section']]);
            $user->sections()->attach($section);

            return $user;
        });

        if (! $user) {
            return redirect()->route('login')->with('status', 'Вы уже зарегистрированы. Пожалуйста, авторизуйтесь');
        }

        event(new Registered($user));

        return redirect(RouteServiceProvider::HOME);
    }
}
