<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\WebhookEndpoint;
use App\Notifications\WebhookEndpointFailing;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Webhook Settings'])]
class WebhookSettings extends Component
{
    public string $uiState = 'normal';

    public string $webhookUrl = '';

    public bool $showSetupNotice = false;

    public ?string $successMessage = null;

    public ?string $testResult = null;

    public ?string $testError = null;

    public ?string $revealedSecret = null;

    public bool $showRegenerateModal = false;

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
            $this->showSetupNotice = true;

            return;
        }

        $this->showSetupNotice = false;
        $url = $endpoint->url;
        $this->webhookUrl = str_starts_with($url, 'https://') ? substr($url, 8) : $url;
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

        $endpoint = WebhookEndpoint::query()->first();

        if ($endpoint === null) {
            $secret = bin2hex(random_bytes(32));
            WebhookEndpoint::create([
                'user_id' => Auth::id(),
                'url' => $url,
                'enabled_events' => ['deposit.credited'],
                'secret' => $secret,
            ]);
        } else {
            $secret = $endpoint->secret;
            $endpoint->update([
                'url' => $url,
                'enabled_events' => ['deposit.credited'],
            ]);
        }

        $this->successMessage = 'Webhook endpoint saved. Credited deposits will be sent to this URL.';
        $this->testResult = null;
        $this->testError = null;
        $this->showSetupNotice = false;
        $this->revealedSecret = $secret;
    }

    public function regenerate(): void
    {
        $endpoint = WebhookEndpoint::query()->first();

        if ($endpoint === null) {
            $this->showRegenerateModal = false;

            return;
        }

        $secret = bin2hex(random_bytes(32));
        $endpoint->update(['secret' => $secret]);

        $this->revealedSecret = $secret;
        $this->successMessage = 'Webhook secret regenerated. Copy it now — you won\'t see it again.';
        $this->showRegenerateModal = false;
        $this->testResult = null;
        $this->testError = null;
    }

    public function cancelRegenerate(): void
    {
        $this->showRegenerateModal = false;
    }

    public function test(WebhookDispatcher $dispatcher): void
    {
        $this->resetErrorBag();
        $this->successMessage = null;
        $this->testResult = null;

        if ($this->webhookUrl === '') {
            $this->addError('webhookUrl', 'Webhook URL must use https://');

            return;
        }

        $path = preg_replace('#^https?://#', '', $this->webhookUrl);
        $url = 'https://'.$path;

        if (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addError('webhookUrl', 'Webhook URL must use https://');

            return;
        }

        $endpoint = WebhookEndpoint::query()->first();
        $secret = $endpoint?->secret ?? bin2hex(random_bytes(32));

        $testEndpoint = new WebhookEndpoint([
            'url' => $url,
            'secret' => $secret,
        ]);

        if ($dispatcher->test($testEndpoint)) {
            $this->testResult = 'success';
            $this->testError = null;
        } else {
            $this->testResult = 'failure';
            $this->testError = 'The test delivery failed. Check that your endpoint responds with any HTTP 2xx status code.';

            Auth::user()?->notify(new WebhookEndpointFailing($url));
        }
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = null;
        $this->testResult = null;
        $this->testError = null;
        $this->resetErrorBag();
        $this->loadEndpoint();
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.webhook-settings');
    }
}
