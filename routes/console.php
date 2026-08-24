<?php

declare(strict_types=1);

use App\Console\Commands\CloseInactiveSupportTickets;
use App\Console\Commands\DeleteExpiredAccounts;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DeleteExpiredAccounts::class)->daily();
Schedule::command(CloseInactiveSupportTickets::class)->daily();
