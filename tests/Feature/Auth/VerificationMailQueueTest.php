<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Jobs\SendUserEmailVerification;
use App\Mail\UserEmailVerificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class VerificationMailQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_mail_is_retried_three_times_and_recorded_as_failed(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->unverified()->create(['is_blocked' => true]);
        $user->update(['is_blocked' => false]);

        $this->assertDatabaseCount('jobs', 1);
        Mail::shouldReceive('send')
            ->times(4)
            ->andThrow(new RuntimeException('SMTP unavailable'));

        foreach ([60, 300, 900, 0] as $delay) {
            $this->artisan('queue:work', [
                'connection' => 'database',
                '--queue' => 'verification-mail',
                '--once' => true,
                '--sleep' => 0,
                '--timeout' => 0,
            ]);

            if ($delay > 0) {
                $this->assertDatabaseCount('jobs', 1);
                $this->travel($delay)->seconds();
            }
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', [
            'connection' => 'database',
            'queue' => 'verification-mail',
        ]);
    }

    public function test_obsolete_verification_mail_is_not_sent(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->unverified()->create(['is_blocked' => true]);
        $user->update(['is_blocked' => false]);
        $job = Queue::pushed(SendUserEmailVerification::class)->first();
        $this->assertNotNull($job);

        DB::table('users')->where('id', $user->id)->update(['email' => 'other@example.com']);
        $job->handle();

        Mail::assertNotSent(UserEmailVerificationMail::class);
    }

    public function test_mail_is_sent_after_a_temporary_failure(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->unverified()->create(['is_blocked' => true]);
        $user->update(['is_blocked' => false]);

        $attempts = 0;
        Mail::shouldReceive('send')->twice()->andReturnUsing(function () use (&$attempts): void {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('SMTP unavailable');
            }
        });

        $options = [
            'connection' => 'database',
            '--queue' => 'verification-mail',
            '--once' => true,
            '--sleep' => 0,
            '--timeout' => 0,
        ];

        $this->artisan('queue:work', $options);
        $this->assertDatabaseCount('jobs', 1);
        $this->travel(60)->seconds();
        $this->artisan('queue:work', $options);

        $this->assertSame(2, $attempts);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_queue_insertion_rolls_back_with_approval(): void
    {
        $user = User::factory()->unverified()->create(['is_blocked' => true]);

        try {
            DB::transaction(function () use ($user): void {
                $user->update(['is_blocked' => false]);
                throw new RuntimeException('Roll back approval');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Roll back approval', $exception->getMessage());
        }

        $this->assertTrue($user->fresh()->is_blocked);
        $this->assertNull($user->fresh()->unblocked_at);
        $this->assertDatabaseCount('jobs', 0);
    }
}
