<?php

use App\Models\AuthenticationIdentity;
use App\Models\EmailChangeRequest;
use App\Models\OtpChallenge;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('creates authentication security tables and extends the existing users table', function (): void {
    expect(Schema::hasTable('authentication_identities'))->toBeTrue()
        ->and(Schema::hasTable('otp_challenges'))->toBeTrue()
        ->and(Schema::hasTable('email_change_requests'))->toBeTrue()
        ->and(Schema::hasTable('security_events'))->toBeTrue()
        ->and(Schema::hasColumns('users', [
            'is_active',
            'deletion_requested_at',
            'deleted_at',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
        ]))->toBeTrue();
});

it('defines encrypted and hidden sensitive model fields', function (): void {
    expect((new AuthenticationIdentity)->getCasts())->toHaveKey('provider_user_id', 'encrypted')
        ->and((new AuthenticationIdentity)->getCasts())->toHaveKey('access_token', 'encrypted')
        ->and((new EmailChangeRequest)->getCasts())->toHaveKey('pending_email', 'encrypted')
        ->and((new User)->getCasts())->toHaveKey('two_factor_secret', 'encrypted')
        ->and((new User)->getHidden())->toContain('two_factor_secret', 'two_factor_recovery_codes');
});

it('defines authentication model relationships', function (): void {
    $user = new User;

    expect($user->authenticationIdentities()->getRelated())->toBeInstanceOf(AuthenticationIdentity::class)
        ->and($user->otpChallenges()->getRelated())->toBeInstanceOf(OtpChallenge::class)
        ->and($user->emailChangeRequests()->getRelated())->toBeInstanceOf(EmailChangeRequest::class)
        ->and($user->securityEvents()->getRelated())->toBeInstanceOf(SecurityEvent::class);
});
