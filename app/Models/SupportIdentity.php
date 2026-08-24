<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SupportIdentity extends Model
{
    protected $fillable = ['role', 'name', 'avatar'];

    public const ROLES = [
        'support',
        'sales',
        'management',
        'administration',
    ];

    public function displayName(): string
    {
        return $this->name ? "{$this->name} from {$this->label()}" : $this->label();
    }

    public function label(): string
    {
        return ucfirst($this->role);
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar) {
            return null;
        }

        if (str_starts_with($this->avatar, 'http')) {
            return $this->avatar;
        }

        return Storage::disk('public')->url($this->avatar);
    }

    public static function forRole(string $role): self
    {
        return self::query()->firstOrCreate(['role' => $role], [
            'name' => null,
            'avatar' => null,
        ]);
    }

    public static function availableAvatars(): array
    {
        $files = Storage::disk('public')->files('support-agents');

        return collect($files)
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.png'))
            ->map(fn (string $path) => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ])
            ->values()
            ->all();
    }
}
