<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deposit;
use App\Models\PlatformSettings;
use Illuminate\Support\Carbon;

final class ShortPayment
{
    /**
     * Totals across the open short payments on a deposit's address: the
     * effective minimum (lowest of the current network minimum and each
     * member's snapshot), the combined received total, and how much more
     * the customer must send before it all credits together.
     *
     * @return array{minimum: string, received_total: string, amount_needed: string, expires_at: ?Carbon}
     */
    public static function summary(Deposit $deposit): array
    {
        $shorts = Deposit::query()
            ->withoutGlobalScope('owner')
            ->where('deposit_address_id', $deposit->deposit_address_id)
            ->where('status', 'below_minimum')
            ->where(function ($query) use ($deposit): void {
                $query->where('expires_at', '>', now())
                    ->orWhere('id', $deposit->id);
            })
            ->get(['gross_amount', 'minimum_amount']);

        $minimum = (string) PlatformSettings::networkSetting($deposit->network)->min_deposit;
        $receivedTotal = '0';

        foreach ($shorts as $short) {
            $receivedTotal = bcadd($receivedTotal, (string) $short->gross_amount, 8);

            if ($short->minimum_amount !== null && bccomp((string) $short->minimum_amount, $minimum, 8) < 0) {
                $minimum = (string) $short->minimum_amount;
            }
        }

        $needed = bccomp($minimum, $receivedTotal, 8) > 0 ? bcsub($minimum, $receivedTotal, 8) : '0';

        return [
            'minimum' => self::formatAmount($deposit->network, $minimum),
            'received_total' => self::formatAmount($deposit->network, $receivedTotal),
            'amount_needed' => self::formatAmount($deposit->network, $needed),
            'expires_at' => $deposit->expires_at,
        ];
    }

    /**
     * The minimum that applied to a forfeited short payment: its snapshot
     * when present, otherwise the current network minimum.
     */
    public static function minimumFor(Deposit $deposit): string
    {
        $minimum = $deposit->minimum_amount !== null
            ? (string) $deposit->minimum_amount
            : (string) PlatformSettings::networkSetting($deposit->network)->min_deposit;

        return self::formatAmount($deposit->network, $minimum);
    }

    private static function formatAmount(string $network, string $amount): string
    {
        $decimals = Network::exists($network) ? Network::decimals($network) : 8;

        return number_format((float) $amount, $decimals, '.', '');
    }
}
