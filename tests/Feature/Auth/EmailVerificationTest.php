<?php

namespace Tests\Feature\Auth;

use App\Jobs\SendUserEmailVerification;
use App\Models\User;
use App\Notifications\UserEmailVerificationNotification;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($user)->get('/verify-email');

        $response->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_blocked' => false,
            'unblocked_at' => now(),
        ]);

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(RouteServiceProvider::HOME.'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
            'is_blocked' => false,
            'unblocked_at' => now(),
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $response = $this->get($verificationUrl);

        $response->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_approving_an_unverified_user_sends_a_seven_day_verification_link(): void
    {
        Queue::fake();
        $user = User::factory()->unverified()->create([
            'is_blocked' => true,
            'unblocked_at' => null,
        ]);

        $user->update(['is_blocked' => false]);

        $user->refresh();
        $this->assertNotNull($user->unblocked_at);
        Queue::assertPushed(SendUserEmailVerification::class, function (SendUserEmailVerification $job) use ($user): bool {
            parse_str(parse_url($job->verificationUrl, PHP_URL_QUERY), $query);

            $this->assertSame($user->email, $job->email);
            $this->assertSame('database', $job->connection);
            $this->assertSame('verification-mail', $job->queue);
            $this->assertSame(4, $job->tries);
            $this->assertSame([60, 300, 900], $job->backoff);
            $this->assertTrue(URL::hasValidSignature(Request::create($job->verificationUrl)));
            $this->assertSame(
                $user->unblocked_at->copy()->addDays(7)->timestamp,
                (int) $query['expires']
            );

            return true;
        });
    }

    public function test_verification_link_cannot_be_used_before_admin_approval(): void
    {
        $user = User::factory()->unverified()->create([
            'is_blocked' => true,
            'unblocked_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addDays(7),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->get($verificationUrl)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_approval_sends_a_rendered_email_to_the_user(): void
    {
        config(['mail.default' => 'array']);
        Queue::fake();
        $messages = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$messages): void {
            $messages[] = $event->message;
        });

        $user = User::factory()->unverified()->create([
            'first_name' => 'Иван',
            'last_name' => 'Иванов',
            'second_name' => null,
            'email' => 'user@example.com',
            'is_blocked' => true,
        ]);

        $user->update(['is_blocked' => false]);

        $job = Queue::pushed(SendUserEmailVerification::class)->first();
        $this->assertNotNull($job);
        $job->handle();

        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame('user@example.com', $message->getTo()[0]->getAddress());
        $this->assertSame('Подтверждение адреса электронной почты', $message->getSubject());

        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        $url = (new UserEmailVerificationNotification())->toMail($user->fresh())->verificationUrl;

        $this->assertStringContainsString('Иванов Иван', $html);
        $this->assertStringContainsString('Подтвердить почту', $html);
        $this->assertStringContainsString('7 дней', $html);
        $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES, 'UTF-8'), $html);
        $this->assertStringContainsString('Иванов Иван', $text);
        $this->assertStringContainsString('7 дней', $text);
        $this->assertStringContainsString($url, $text);
    }
}
