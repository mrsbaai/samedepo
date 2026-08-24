<?php

declare(strict_types=1);

namespace App\Actions\Authentication;

use App\Models\User;

class ResolvePostSigninRedirect
{
    public static function for(User $user): string
    {
        if ($user->is_admin) {
            return route('admin.dashboard');
        }

        return session()->pull('url.intended', route('dashboard'));
    }
}
