<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Events\Authentication\AuthenticationEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Active sessions', 'description' => 'Review and revoke devices signed in to your account.'])]
class SessionManager extends Component
{
    public string $status = '';

    public string $error = '';

    public function revoke(string $sessionId): void
    {
        $currentSessionId = session()->getId();

        if ($sessionId === $currentSessionId) {
            $this->error = 'You cannot revoke your current session.';

            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $revoked = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $user->id)
            ->delete();

        if ($revoked === 0) {
            $this->error = 'That session is no longer active.';

            return;
        }

        $this->recordRevocation($user, $sessionId);
        $this->error = '';
        $this->status = 'The session has been revoked.';
    }

    public function revokeAllOtherSessions(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $currentSessionId = session()->getId();
        $revoked = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        if ($revoked > 0) {
            $this->recordRevocation($user);
        }

        $this->error = '';
        $this->status = 'All other sessions have been revoked.';
    }

    public function sessions(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $currentSessionId = session()->getId();

        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): object => (object) [
                'id' => $session->id,
                'ipAddress' => $session->ip_address,
                'userAgent' => $session->user_agent,
                'lastActivity' => $session->last_activity,
                'isCurrent' => $session->id === $currentSessionId,
            ]);
    }

    private function recordRevocation(User $user, ?string $sessionId = null): void
    {
        event(new AuthenticationEvent(
            type: AuthenticationEvent::SESSION_REVOKED,
            user: $user,
            ipAddress: request()->ip(),
            userAgent: request()->userAgent(),
            metadata: $sessionId === null ? [] : ['session_id' => $sessionId],
        ));
    }

    public function render(): mixed
    {
        return view('livewire.authentication.session-manager', [
            'sessions' => $this->sessions(),
        ]);
    }
}
