<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\WithdrawalAddress;
use App\Support\Network;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Withdrawal Settings'])]
class WithdrawalSettings extends Component
{
    public string $uiState = 'normal';

    /**
     * @var array<string, array{network: string, slug: string, address: string}>
     */
    public array $networks = [];

    public string $editingNetwork = '';

    public string $editingAddress = '';

    public bool $showConfirmModal = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState === 'error') {
            return;
        }

        $this->loadAddresses();
    }

    private function loadAddresses(): void
    {
        $addresses = WithdrawalAddress::query()
            ->whereIn('network', Network::enabledKeys())
            ->pluck('address', 'network')
            ->all();

        $this->networks = [];
        foreach (Network::enabledKeys() as $key) {
            $meta = Network::present($key);
            $this->networks[$key] = [
                'network' => $meta['label'],
                'slug' => $meta['slug'],
                'icon' => $meta['icon'],
                'badge' => $meta['badge'],
                'address' => $addresses[$key] ?? '',
            ];
        }
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load withdrawal addresses. Please try again.";
    }

    public function startEdit(string $network, string $currentAddress): void
    {
        $this->editingNetwork = $network;
        $this->editingAddress = $currentAddress;
        $this->showConfirmModal = false;
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingNetwork = '';
        $this->editingAddress = '';
        $this->showConfirmModal = false;
    }

    private function addressRules(): array
    {
        $group = config('networks.address_groups.'.Network::addressGroup($this->editingNetwork));

        return [
            'required',
            'string',
            'max:255',
            'regex:'.$group['address_regex'],
        ];
    }

    private function addressMessages(): array
    {
        $hint = config('networks.address_groups.'.Network::addressGroup($this->editingNetwork).'.address_hint', 'a valid address');

        return ['editingAddress.regex' => "Enter {$hint}."];
    }

    public function confirmSave(): void
    {
        $this->validate([
            'editingAddress' => $this->addressRules(),
        ], $this->addressMessages());

        $this->showConfirmModal = true;
    }

    public function saveAddress(): void
    {
        $validated = $this->validate([
            'editingAddress' => $this->addressRules(),
        ], $this->addressMessages());

        WithdrawalAddress::updateOrCreate(
            [
                'user_id' => Auth::id(),
                'network' => $this->editingNetwork,
            ],
            [
                'address' => $validated['editingAddress'],
            ]
        );

        $this->networks[$this->editingNetwork]['address'] = $validated['editingAddress'];
        $this->editingNetwork = '';
        $this->editingAddress = '';
        $this->showConfirmModal = false;
        $this->successMessage = 'Withdrawal address saved. Future withdrawals for this network will be sent to this address.';
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = null;
        $this->resetErrorBag();
        $this->editingNetwork = '';
        $this->editingAddress = '';

        if ($this->uiState === 'error') {
            return;
        }

        $this->loadAddresses();
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.withdrawal-settings');
    }
}
