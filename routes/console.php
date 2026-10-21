<?php

declare(strict_types=1);

use App\Console\Commands\CloseInactiveSupportTickets;
use App\Console\Commands\DeleteExpiredAccounts;
use App\Console\Commands\ProcessDeposits;
use App\Console\Commands\ProcessWithdrawals;
use App\Console\Commands\ReconcileWithdrawals;
use App\Console\Commands\UpdateUsdValuations;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DeleteExpiredAccounts::class)->daily();
Schedule::command('model:prune', ['--model' => [WebhookDelivery::class]])->daily();
Schedule::command(CloseInactiveSupportTickets::class)->daily();
Schedule::command(ProcessDeposits::class)->everyMinute()->withoutOverlapping(30);
Schedule::command(ProcessWithdrawals::class)->everyMinute()->withoutOverlapping(10);
Schedule::command(ReconcileWithdrawals::class)->everyTenMinutes()->withoutOverlapping(30);
Schedule::command(UpdateUsdValuations::class)->everyFiveMinutes();
