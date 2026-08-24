<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\Authentication\SecurityAlertNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DeleteExpiredAccounts extends Command
{
    protected $signature = 'authentication:cleanup-deleted-accounts';

    protected $description = 'Permanently delete accounts whose deletion grace period has expired.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('authentication.deletion.grace_period_days'));

        $expired = User::query()
            ->whereNotNull('deletion_requested_at')
            ->where('deletion_requested_at', '<', $cutoff)
            ->get();

        foreach ($expired as $user) {
            $this->deleteAccount($user);
        }

        $this->info("{$expired->count()} expired account(s) processed.");

        return self::SUCCESS;
    }

    private function deleteAccount(User $user): void
    {
        $email = $user->email;

        SecurityEvent::query()->create([
            'user_id' => $user->id,
            'event_type' => 'account_deletion_completed',
            'ip_address' => null,
            'user_agent' => 'scheduled cleanup',
            'occurred_at' => now(),
        ]);

        Notification::route('mail', $email)
            ->notify(new SecurityAlertNotification('account_deletion_completed'));

        DB::transaction(function () use ($user): void {
            $user->authenticationIdentities()->delete();
            $user->otpChallenges()->delete();
            $user->emailChangeRequests()->delete();
            $user->securityEvents()->delete();

            $user->forceDelete();
        });
    }
}
