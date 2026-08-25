<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Blockchain\UsdValuationUpdater;
use Illuminate\Console\Command;

class UpdateUsdValuations extends Command
{
    protected $signature = 'app:update-usd-valuations';

    protected $description = 'Update cryptocurrency USD valuations';

    public function handle(UsdValuationUpdater $updater): int
    {
        $updater->update();

        return self::SUCCESS;
    }
}
