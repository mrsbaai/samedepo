<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Deposit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositCredited
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Deposit $deposit) {}
}
