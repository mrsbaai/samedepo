<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResetPasswordWithOtp
{
    public function execute(OtpChallenge $challenge, string $password, Request $request): void
    {
        $user = $challenge->user;

        if (! $user instanceof User || $challenge->consumed_at === null || $challenge->expires_at->isPast()) {
            throw new \RuntimeException('The recovery challenge is no longer valid.');
        }

        $user->forceFill(['password' => $password])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::PASSWORD_CHANGED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));
    }
}
