<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyApprovedUsersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_legacy_approved_unverified_users_are_marked_verified(): void
    {
        $this->travelTo(now()->startOfSecond());

        $legacy = User::factory()->unverified()->create([
            'is_blocked' => false,
            'unblocked_at' => null,
        ]);
        $pending = User::factory()->unverified()->create([
            'is_blocked' => true,
            'unblocked_at' => null,
        ]);
        $recent = User::factory()->unverified()->create([
            'is_blocked' => false,
            'unblocked_at' => now()->subDay(),
        ]);
        $verifiedAt = now()->subMonth();
        $verified = User::factory()->create([
            'is_blocked' => false,
            'email_verified_at' => $verifiedAt,
        ]);

        $migration = require database_path('migrations/2026_11_25_000000_verify_legacy_approved_users.php');
        $migration->up();

        $this->assertTrue($legacy->fresh()->email_verified_at->equalTo(now()));
        $this->assertNull($pending->fresh()->email_verified_at);
        $this->assertNull($recent->fresh()->email_verified_at);
        $this->assertTrue($verified->fresh()->email_verified_at->equalTo($verifiedAt));

        $migration->down();

        $this->assertNotNull($legacy->fresh()->email_verified_at);
        $this->assertTrue($verified->fresh()->email_verified_at->equalTo($verifiedAt));
    }
}
