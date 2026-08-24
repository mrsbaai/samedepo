<?php

declare(strict_types=1);

namespace App\Models;

use App\Fraud\Models\Device;
use App\Fraud\Models\EntityLink;
use App\Fraud\Models\UserRisk;
use App\Notifications\Authentication\VerifyEmailNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'email',
        'password',
        'is_active',
        'is_admin',
        'appearance',
        'deletion_requested_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'deletion_requested_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    public function sendEmailVerificationNotification(): void
    {
        $code = (string) random_int(100000, 999999);

        OtpChallenge::query()
            ->where('email', $this->email)
            ->where('purpose', 'email_verification')
            ->whereNull('consumed_at')
            ->delete();

        OtpChallenge::query()->create([
            'user_id' => $this->id,
            'email' => $this->email,
            'purpose' => 'email_verification',
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('authentication.email_verification.expires_after_minutes')),
        ]);

        $this->notify(new VerifyEmailNotification($code));
    }

    public function hasRequestedDeletion(): bool
    {
        return $this->deletion_requested_at !== null;
    }

    public function authenticationIdentities(): HasMany
    {
        return $this->hasMany(AuthenticationIdentity::class);
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class);
    }

    public function emailChangeRequests(): HasMany
    {
        return $this->hasMany(EmailChangeRequest::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class)->withTimestamps();
    }

    public function ips(): HasMany
    {
        return $this->hasMany(UserIp::class);
    }

    public function risk(): HasOne
    {
        return $this->hasOne(UserRisk::class);
    }

    public function entityLinks(): HasMany
    {
        return $this->hasMany(EntityLink::class);
    }

    public function avatarUrl(): string
    {
        if ($this->is_admin) {
            $adminAvatar = SupportIdentity::forRole('administration')->avatarUrl();

            if ($adminAvatar) {
                return $adminAvatar;
            }
        }

        $fallbackAvatar = urlencode('https://ui-avatars.com/api/?name='.urlencode($this->email).'&size=100&background=18181B&color=f5f5f5');

        return 'https://unavatar.io/'.urlencode($this->email).'?fallback='.$fallbackAvatar;
    }
}
