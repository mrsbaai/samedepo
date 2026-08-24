<?php

namespace FluxPro;

use Illuminate\Support\Facades\Facade;
use Livewire\FluxManager;

/**
 * @see FluxManager
 */
class FluxPro extends Facade
{
    public static function getFacadeAccessor()
    {
        return 'flux-pro';
    }
}
