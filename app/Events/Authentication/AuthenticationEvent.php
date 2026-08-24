<?php

declare(strict_types=1);

namespace App\Events\Authentication;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuthenticationEvent
{
    use Dispatchable, SerializesModels;

    public const USER_SIGNED_UP = 'user_signed_up';

    public const EMAIL_VERIFICATION_REQUESTED = 'email_verification_requested';

    public const EMAIL_VERIFIED = 'email_verified';

    public const USER_SIGNED_IN = 'user_signed_in';

    public const SIGNIN_FAILED = 'signin_failed';

    public const PASSWORD_RESET_REQUESTED = 'password_reset_requested';

    public const PASSWORD_CHANGED = 'password_changed';

    public const EMAIL_CHANGE_REQUESTED = 'email_change_requested';

    public const EMAIL_CHANGE_CANCELLED = 'email_change_cancelled';

    public const EMAIL_CHANGED = 'email_changed';

    public const TWO_FACTOR_ENABLED = 'two_factor_enabled';

    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    public const RECOVERY_CODES_REGENERATED = 'recovery_codes_regenerated';

    public const SESSION_REVOKED = 'session_revoked';

    public const ACCOUNT_DELETED = 'account_deleted';

    public const ACCOUNT_DELETION_COMPLETED = 'account_deletion_completed';

    public const ACCOUNT_DELETION_RECOVERED = 'account_deletion_recovered';

    public const SOCIAL_PROVIDER_LINKED = 'social_provider_linked';

    public const SOCIAL_SIGNIN_FAILED = 'social_signin_failed';

    public function __construct(
        public readonly string $type,
        public readonly ?User $user = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly array $metadata = [],
    ) {}
}
