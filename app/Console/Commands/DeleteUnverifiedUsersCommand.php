<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteUnverifiedUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:delete-unverified';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete users who were unblocked but did not verify their email within 7 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deletedCount = 0;

        User::query()
            ->where('is_blocked', false)
            ->whereNull('email_verified_at')
            ->whereNotNull('unblocked_at')
            ->where('unblocked_at', '<=', now()->subDays(7))
            ->chunkById(100, function ($users) use (&$deletedCount): void {
                $userIds = $users->modelKeys();

                $deletedCount += DB::transaction(function () use ($userIds): int {
                    $expiredIds = User::query()
                        ->whereKey($userIds)
                        ->where('is_blocked', false)
                        ->whereNull('email_verified_at')
                        ->whereNotNull('unblocked_at')
                        ->where('unblocked_at', '<=', now()->subDays(7))
                        ->lockForUpdate()
                        ->pluck('id');

                    if ($expiredIds->isEmpty()) {
                        return 0;
                    }

                    DB::table('section_user')
                        ->whereIn('user_id', $expiredIds)
                        ->delete();

                    return User::query()->whereKey($expiredIds)->delete();
                });
            });

        $this->info("Deleted {$deletedCount} unverified users.");

        return Command::SUCCESS;
    }
}
