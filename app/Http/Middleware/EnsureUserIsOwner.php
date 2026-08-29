<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'owner') {
            $request->attributes->set('forbidden.source', 'ensure_owner');
            $request->attributes->set('forbidden.reason', 'Owner role required');
            abort(403);
        }

        return $next($request);
    }
}
