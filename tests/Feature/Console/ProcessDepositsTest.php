<?php

use App\Services\Blockchain\DepositCreditor;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\TreasurySweepService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

test('it logs processing lifecycle and runs every processing service', function () {
    Log::shouldReceive('info')->once()->with('Deposit processing started.')->ordered();
    Log::shouldReceive('info')->once()->with('Deposit processing completed.')->ordered();
    mock(DepositScanner::class)->shouldReceive('scan')->once();
    mock(DepositCreditor::class)->shouldReceive('credit')->once();
    mock(TreasurySweepService::class)->shouldReceive('sweep')->once();

    artisan('app:process-deposits')->assertSuccessful();
});

test('deposit processing is scheduled without overlapping', function () {
    $event = collect(Schedule::events())->first(fn (Event $event) => str_contains($event->command ?? '', 'app:process-deposits'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue();
});
