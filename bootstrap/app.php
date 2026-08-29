<?php

use App\Http\Middleware\ApiKeyAuthentication;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\GuardAgainstThreats;
use App\Http\Middleware\IdentifyDevice;
use App\Http\Middleware\SetUserAppearance;
use App\Http\Middleware\TrustCloudflare;
use App\Security\ForbiddenEventRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: null,
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(TrustCloudflare::class);
        $middleware->redirectGuestsTo('/signin');
        $middleware->web(append: GuardAgainstThreats::class);
        $middleware->api(append: GuardAgainstThreats::class);
        $middleware->web(append: IdentifyDevice::class);
        $middleware->web(append: SetUserAppearance::class);
        $middleware->encryptCookies(except: ['device_fp']);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'owner' => EnsureUserIsOwner::class,
        ]);
        $middleware->prependToPriorityList(ThrottleRequests::class, ApiKeyAuthentication::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (HttpExceptionInterface $e): ?Response {
            app(ForbiddenEventRecorder::class)->record($e, request());

            return null;
        });
    })->create();
