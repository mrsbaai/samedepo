<?php

namespace FluxPro;

use Flux\FluxPro;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class FluxProServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->alias(FluxProManager::class, 'flux-pro');

        $this->app->singleton(FluxProManager::class);

        $loader = AliasLoader::getInstance();
        $loader->alias('FluxPro', FluxPro::class);
    }

    public function boot(): void
    {
        $this->bootComponentPath();
    }

    public function bootComponentPath()
    {
        Blade::anonymousComponentPath(__DIR__.'/../stubs/resources/views/flux', 'flux');
    }
}
