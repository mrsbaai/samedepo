<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RequestAccountDeletion
{
    public function execute(User $user, Request $request): void
    {
        $user->forceFill([
            'deletion_requested_at' => now(),
            'is_active' => false,
        ])->save();

        DB::table('sessions')->where('user_id', $user->id)->delete();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::ACCOUNT_DELETED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }
}
