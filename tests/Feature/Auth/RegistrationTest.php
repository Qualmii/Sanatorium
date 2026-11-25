<?php

namespace Tests\Feature\Auth;

use App\Models\Section;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'phone' => '+79991234567',
            'section' => 1,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'is_blocked' => true,
            'email_verified_at' => null,
        ]);
    }

    public function test_registration_replaces_an_unverified_account_with_the_same_email(): void
    {
        $oldUser = User::factory()->unverified()->create([
            'email' => 'test@example.com',
            'phone' => '+79991234567',
            'is_blocked' => false,
            'unblocked_at' => now()->subDays(7),
        ]);
        $section = Section::query()->create(['section' => 10]);
        $oldUser->sections()->attach($section);

        $response = $this->post('/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'phone' => '+79991234567',
            'section' => 11,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertDatabaseMissing('users', ['id' => $oldUser->id]);
        $newUser = User::query()->where('email', 'test@example.com')->sole();
        $this->assertTrue($newUser->is_blocked);
        $this->assertDatabaseHas('section_user', [
            'user_id' => $newUser->id,
            'section_id' => Section::query()->where('section', 11)->value('id'),
        ]);
        $this->assertDatabaseMissing('section_user', ['user_id' => $oldUser->id]);
    }

    public function test_registration_cannot_reuse_a_verified_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'test@example.com',
            'phone' => '+79991234567',
        ]);

        $response = $this->from('/register')->post('/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'phone' => '+79997654321',
            'section' => 1,
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHas('status', 'Вы уже зарегистрированы. Пожалуйста, авторизуйтесь');
        $this->assertDatabaseHas('users', ['id' => $existingUser->id]);
    }

    public function test_registration_preserves_unexpired_unverified_account(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'test@example.com',
            'phone' => '+79991234567',
            'is_blocked' => false,
            'unblocked_at' => now()->subDays(6),
        ]);

        $response = $this->from('/register')->post('/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'phone' => $user->phone,
            'section' => 1,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'email' => 'Ваша почта уже была зарегистрирована и вам направлено письмо для подтверждения почты. Пожалуйста, найдите его во входящих письмах и следуйте инструкциям',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_registration_preserves_a_pending_application(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'test@example.com',
            'phone' => '+79991234567',
            'is_blocked' => true,
        ]);

        $this->from('/register')->post('/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'phone' => $user->phone,
            'section' => 1,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
