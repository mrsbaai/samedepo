<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Must run during the register phase: Fortify's own package provider
        // boots (and registers its routes) before this app provider does,
        // so calling ignoreRoutes() in boot() would be too late.
        Fortify::ignoreRoutes();
    }
}
