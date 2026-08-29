<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->is_admin) {
            $request->attributes->set('forbidden.source', 'ensure_admin');
            $request->attributes->set('forbidden.reason', 'Admin role required');
            abort(403);
        }

        return $next($request);
    }
}
