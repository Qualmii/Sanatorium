<?php

namespace Tests\Feature\Auth;

use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnverifiedUserCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_unverified_users_are_deleted_with_their_section_links(): void
    {
        $expiredUser = User::factory()->unverified()->create([
            'is_blocked' => false,
            'unblocked_at' => now()->subDays(7),
        ]);
        $section = Section::query()->create(['section' => 1]);
        $expiredUser->sections()->attach($section);

        $this->artisan('users:delete-unverified')
            ->expectsOutput('Deleted 1 unverified users.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $expiredUser->id]);
        $this->assertDatabaseMissing('section_user', ['user_id' => $expiredUser->id]);
    }

    public function test_cleanup_preserves_verified_recent_and_unapproved_users(): void
    {
        $verifiedUser = User::factory()->create([
            'is_blocked' => false,
            'unblocked_at' => now()->subDays(8),
        ]);
        $recentUser = User::factory()->unverified()->create([
            'is_blocked' => false,
            'unblocked_at' => now()->subDays(6),
        ]);
        $unapprovedUser = User::factory()->unverified()->create([
            'is_blocked' => true,
            'unblocked_at' => now()->subDays(8),
        ]);

        $this->artisan('users:delete-unverified')
            ->expectsOutput('Deleted 0 unverified users.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $verifiedUser->id]);
        $this->assertDatabaseHas('users', ['id' => $recentUser->id]);
        $this->assertDatabaseHas('users', ['id' => $unapprovedUser->id]);
    }
}
