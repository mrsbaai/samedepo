<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\View\View;

class ApiDocsController
{
    public function __invoke(): View
    {
        $spec = app(Generator::class)
            ->setThrowExceptions(false)
            ->__invoke(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

        return view('public.api-docs', [
            'spec' => $spec,
        ]);
    }
}
