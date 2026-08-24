<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Webhook Settings'])]
class WebhookSettings extends Component
{
    public string $uiState = 'normal';

    public string $webhookUrl = '';

    public bool $eventCreditedDeposit = false;

    public bool $eventWithdrawalStatus = false;

    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');

        if ($this->uiState === 'error') {
            return;
        }

        $this->loadEndpoint();
    }

    private function loadEndpoint(): void
    {
        $endpoint = WebhookEndpoint::query()->first();

        if ($endpoint === null) {
            $this->webhookUrl = '';
            $this->eventCreditedDeposit = false;
            $this->eventWithdrawalStatus = false;

            return;
        }

        $url = $endpoint->url;
        $this->webhookUrl = str_starts_with($url, 'https://') ? substr($url, 8) : $url;
        $events = $endpoint->enabled_events ?? [];
        $this->eventCreditedDeposit = in_array('deposit.credited', $events, true);
        $this->eventWithdrawalStatus = in_array('withdrawal.status', $events, true);
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load webhook settings. Please try again.";
    }

    public function save(): void
    {
        $validated = $this->validate([
            'webhookUrl' => ['required', 'string', 'max:2048'],
        ]);

        $path = preg_replace('#^https?://#', '', $validated['webhookUrl']);
        $url = 'https://'.$path;

        if (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addError('webhookUrl', 'Webhook URL must use https://');

            return;
        }

        $enabledEvents = [];
        if ($this->eventCreditedDeposit) {
            $enabledEvents[] = 'deposit.credited';
        }
        if ($this->eventWithdrawalStatus) {
            $enabledEvents[] = 'withdrawal.status';
        }

        $endpoint = WebhookEndpoint::query()->first();

        if ($endpoint === null) {
            WebhookEndpoint::create([
                'user_id' => Auth::id(),
                'url' => $url,
                'enabled_events' => $enabledEvents,
                'secret' => bin2hex(random_bytes(32)),
            ]);
        } else {
            $endpoint->update([
                'url' => $url,
                'enabled_events' => $enabledEvents,
            ]);
        }

        $this->successMessage = 'Webhook endpoint saved. Future events will be sent to this URL.';
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = null;
        $this->resetErrorBag();
        $this->loadEndpoint();
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.webhook-settings');
    }
}
