<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $providedKey = substr($header, 7);

        if ($providedKey === '') {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $apiKey = ApiKey::query()
            ->where('status', 'active')
            ->get()
            ->first(fn (ApiKey $key) => Hash::check($providedKey, $key->key_hash));

        if ($apiKey === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $user = $apiKey->user;

        Auth::guard()->setUser($user);
        $request->setUserResolver(fn () => $user);

        $apiKey->update(['last_used_at' => now()]);

        return $next($request);
    }
}
