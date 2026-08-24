<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Notifications\Support\TicketAutoClosedNotification;
use Illuminate\Console\Command;

class CloseInactiveSupportTickets extends Command
{
    protected $signature = 'support:close-inactive-tickets';

    protected $description = 'Close open support tickets that have had no activity for a configured number of days, and notify the ticket owner.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('support.auto_close_after_days'));

        $inactiveTickets = SupportTicket::query()
            ->where('status', SupportTicket::STATUS_OPEN)
            ->where('last_message_at', '<', $cutoff)
            ->get();

        foreach ($inactiveTickets as $ticket) {
            $ticket->update(['status' => SupportTicket::STATUS_CLOSED]);
            $ticket->user->notify(new TicketAutoClosedNotification($ticket));
        }

        $this->info("{$inactiveTickets->count()} inactive ticket(s) closed.");

        return self::SUCCESS;
    }
}
