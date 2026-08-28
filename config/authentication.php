<?php

return [
    'email_verification' => [
        'expires_after_minutes' => 60,
        'resend_after_seconds' => 60,
    ],

    'password' => [
        'minimum_length' => 8,
        'require_mixed_case' => true,
        'require_numbers' => true,
        'require_symbols' => false,
        'uncompromised' => true,
    ],

    'password_reset' => [
        'expires_after_minutes' => 15,
    ],

    'otp' => [
        'length' => 6,
        'expires_after_minutes' => 15,
        'maximum_attempts' => 5,
        'maximum_resends' => 3,
        'resend_after_seconds' => 60,
    ],

    'rate_limits' => [
        'signin' => 5,
        'signup' => 5,
        'password_recovery' => 5,
        'otp_verification' => 5,
        'otp_resend' => 3,
        'verification_resend' => 3,
        'two_factor' => 5,
    ],

    'email_change' => [
        'expires_after_minutes' => 60,
    ],

    'deletion' => [
        'grace_period_days' => 30,
    ],

    'captcha' => [
        'enabled' => (bool) env('CAPTCHA_ENABLED', false),
        'workflows' => [],
    ],

    'two_factor' => [
        'enabled' => true,
    ],

    'remember' => [
        'days' => (int) env('REMEMBER_ME_DAYS', 30),
    ],

    'sessions' => [
        'lifetime_minutes' => (int) env('SESSION_LIFETIME', 120),
    ],

    'security_notifications' => [
        'new_device_signin' => (bool) env('SECURITY_NEW_DEVICE_SIGNIN', true),
        'suspicious_signin' => (bool) env('SECURITY_SUSPICIOUS_SIGNIN', true),
        'password_changed' => (bool) env('SECURITY_PASSWORD_CHANGED', true),
        'two_factor_changed' => (bool) env('SECURITY_TWO_FACTOR_CHANGED', true),
        'email_changed' => (bool) env('SECURITY_EMAIL_CHANGED', true),
        'session_revoked' => (bool) env('SECURITY_SESSION_REVOKED', true),
        'account_deleted' => (bool) env('SECURITY_ACCOUNT_DELETED', true),
    ],

    'social' => [
        'google' => [
            'enabled' => (bool) env('GOOGLE_SIGNIN_ENABLED', false),
        ],
        'github' => [
            'enabled' => (bool) env('GITHUB_SIGNIN_ENABLED', false),
        ],
        'apple' => [
            'enabled' => (bool) env('APPLE_SIGNIN_ENABLED', false),
        ],
        'microsoft' => [
            'enabled' => (bool) env('MICROSOFT_SIGNIN_ENABLED', false),
        ],
    ],
];
