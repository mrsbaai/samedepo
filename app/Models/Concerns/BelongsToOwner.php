<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToOwner
{
    /**
     * Relationship method used to locate the owning user.
     */
    protected static function ownerScopeRelation(): string
    {
        return 'user';
    }

    /**
     * Column on the related owner table that matches the authenticated user id.
     */
    protected static function ownerScopeKey(): string
    {
        return 'id';
    }

    protected static function bootBelongsToOwner(): void
    {
        static::addGlobalScope('owner', function (Builder $builder) {
            if (! Auth::check()) {
                return;
            }

            $relation = static::ownerScopeRelation();
            $key = static::ownerScopeKey();
            $userId = Auth::id();

            $builder->whereHas($relation, function (Builder $query) use ($key, $userId) {
                $query->where($key, $userId);
            });
        });
    }
}
