<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        $response = $this->post('/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create(['phone' => '+79991234567']);

        $this->post('/login', [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_unverified_users_cannot_authenticate(): void
    {
        $user = User::factory()->unverified()->create([
            'phone' => '+79991234567',
            'is_blocked' => false,
        ]);

        $response = $this->post('/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
