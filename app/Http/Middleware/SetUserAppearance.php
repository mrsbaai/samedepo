<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserAppearance
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            $appearance = $request->user()->appearance;

            config(['app.appearance' => in_array($appearance, ['dark', 'light'], true) ? $appearance : 'dark']);
        }

        return $next($request);
    }
}
