<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Concerns;

use App\Models\Deposit;
use App\Models\PlatformSettings;
use App\Services\Blockchain\DepositCreditor;
use App\Support\DepositRow;
use Flux\Flux;
use Livewire\Attributes\Computed;

trait CreditsShortPayments
{
    public bool $showCreditModal = false;

    public ?int $creditDepositId = null;

    public ?string $creditError = null;

    public function confirmCreditAnyway(int $depositId): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $this->creditDepositId = $depositId;
        $this->creditError = null;
        $this->showCreditModal = true;
        unset($this->creditDeposit);
    }

    #[Computed]
    public function creditDeposit(): ?array
    {
        if ($this->creditDepositId === null) {
            return null;
        }

        $deposit = Deposit::query()->withoutGlobalScope('owner')
            ->with(['user', 'customer' => fn ($query) => $query->withoutGlobalScope('owner')])
            ->find($this->creditDepositId);

        if ($deposit === null) {
            return null;
        }

        $meta = DepositRow::meta($deposit->network);
        $feePercent = (string) ($deposit->user?->deposit_fee_override
            ?? PlatformSettings::instance()->global_deposit_fee_percent);
        $gross = (string) $deposit->gross_amount;
        $net = bcsub($gross, bcmul($gross, bcdiv($feePercent, '100', 8), 8), 8);

        return [
            'id' => $deposit->id,
            'status' => $deposit->status,
            'owner' => ($deposit->user?->name ?? 'Unknown').' ('.($deposit->user?->email ?? '').')',
            'customerReference' => $deposit->customer?->customer_reference,
            'networkLabel' => $meta['label'],
            'symbol' => $meta['symbol'],
            'gross' => number_format((float) $gross, $meta['decimals'], '.', ''),
            'feePercent' => $feePercent,
            'net' => number_format((float) $net, $meta['decimals'], '.', ''),
        ];
    }

    public function creditAnyway(DepositCreditor $creditor): void
    {
        abort_unless(auth()->user()?->is_admin, 403);

        $deposit = Deposit::query()->withoutGlobalScope('owner')->find($this->creditDepositId);

        $this->showCreditModal = false;

        if ($deposit === null) {
            Flux::toast(heading: 'Credit failed', text: 'Deposit not found.', variant: 'danger');

            return;
        }

        try {
            $creditor->creditManually($deposit, auth()->user());
        } catch (\DomainException $e) {
            $this->creditError = $e->getMessage();
            Flux::toast(heading: 'Credit failed', text: $e->getMessage(), variant: 'danger');

            return;
        }

        Flux::toast(heading: 'Deposit credited', text: 'The deposit was credited to the owner.', variant: 'success');
        $this->creditDepositId = null;
        $this->creditError = null;

        unset($this->creditDeposit, $this->entries, $this->paginatedEntries, $this->deposits);
    }
}
