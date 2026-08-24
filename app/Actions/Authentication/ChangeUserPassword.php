<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChangeUserPassword
{
    public function execute(User $user, string $password, Request $request): void
    {
        $user->forceFill(['password' => $password])->save();

        $currentSessionId = session()->getId();
        $revokedSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        event(new AuthenticationEvent(
            type: AuthenticationEvent::PASSWORD_CHANGED,
            user: $user,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        ));

        if ($revokedSessions > 0) {
            event(new AuthenticationEvent(
                type: AuthenticationEvent::SESSION_REVOKED,
                user: $user,
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            ));
        }
    }
}
