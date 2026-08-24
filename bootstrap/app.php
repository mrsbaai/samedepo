<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\GuardAgainstThreats;
use App\Http\Middleware\IdentifyDevice;
use App\Http\Middleware\SetUserAppearance;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/signin');
        $middleware->prepend(GuardAgainstThreats::class);
        $middleware->web(append: IdentifyDevice::class);
        $middleware->web(append: SetUserAppearance::class);
        $middleware->encryptCookies(except: ['device_fp']);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'owner' => EnsureUserIsOwner::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
