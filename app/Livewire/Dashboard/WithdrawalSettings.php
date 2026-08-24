<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\WithdrawalAddress;
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

    private const NETWORKS = [
        'bitcoin' => ['slug' => 'bitcoin', 'label' => 'Bitcoin'],
        'usdt_trc20' => ['slug' => 'usdt-trc20', 'label' => 'USDT (TRC20)'],
        'usdt_erc20' => ['slug' => 'usdt-erc20', 'label' => 'USDT (ERC20)'],
    ];

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
            ->whereIn('network', array_keys(self::NETWORKS))
            ->pluck('address', 'network')
            ->all();

        $this->networks = [];
        foreach (self::NETWORKS as $key => $meta) {
            $this->networks[$key] = [
                'network' => $meta['label'],
                'slug' => $meta['slug'],
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

    public function confirmSave(): void
    {
        $this->validate([
            'editingAddress' => ['required', 'string', 'max:255'],
        ]);

        $this->showConfirmModal = true;
    }

    public function saveAddress(): void
    {
        $validated = $this->validate([
            'editingAddress' => ['required', 'string', 'max:255'],
        ]);

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
