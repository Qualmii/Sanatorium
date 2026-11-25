<?php

namespace Tests\Feature;

use App\Jobs\SendUserEmailVerification;
use App\Models\User;
use App\Notifications\UserEmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();
        Queue::fake();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/login');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->unblocked_at);
        $this->assertGuest();
        Queue::assertPushed(SendUserEmailVerification::class, fn (SendUserEmailVerification $job): bool => $job->userId === $user->id
            && $job->email === 'test@example.com'
            && $job->emailChanged
        );
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
        Queue::assertNotPushed(SendUserEmailVerification::class);
    }

    public function test_new_email_can_be_confirmed_and_verified_account_is_preserved(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'phone' => '+79991234567',
            'unblocked_at' => now()->subDays(30),
        ]);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertRedirect('/login');

        $job = Queue::pushed(SendUserEmailVerification::class)->first();
        $this->assertNotNull($job);
        $this->assertTrue($job->emailChanged);
        $this->assertStringContainsString(
            'вы изменили адрес',
            (new UserEmailVerificationNotification(true))->toMail($user->fresh())->render()
        );

        $this->post('/login', ['phone' => $user->phone, 'password' => 'password'])
            ->assertSessionHasErrors('phone');
        $this->get($job->verificationUrl)->assertRedirect('/?verified=1');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->artisan('users:delete-unverified')->assertExitCode(0);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_unconfirmed_changed_email_is_deleted_after_seven_days(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertRedirect('/login');

        $this->travel(7)->days();
        $this->artisan('users:delete-unverified')->assertExitCode(0);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
