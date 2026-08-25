<?php

declare(strict_types=1);

use App\Console\Commands\CloseInactiveSupportTickets;
use App\Console\Commands\DeleteExpiredAccounts;
use App\Console\Commands\ProcessDeposits;
use App\Console\Commands\ProcessWithdrawals;
use App\Console\Commands\UpdateUsdValuations;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DeleteExpiredAccounts::class)->daily();
Schedule::command(CloseInactiveSupportTickets::class)->daily();
Schedule::command(ProcessDeposits::class)->everyMinute();
Schedule::command(ProcessWithdrawals::class)->everyMinute();
Schedule::command(UpdateUsdValuations::class)->everyFiveMinutes();
