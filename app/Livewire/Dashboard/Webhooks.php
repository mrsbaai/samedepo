<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Jobs\DeliverWebhook;
use App\Models\Customer;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Flux\DateRange;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Webhooks'])]
class Webhooks extends Component
{
    use WithPagination;

    public const EVENT_OPTIONS = [
        'deposit.pending' => 'Deposit pending',
        'deposit.credited' => 'Deposit credited',
        'deposit.below_minimum' => 'Deposit below minimum',
        'deposit.forfeited' => 'Deposit expired',
    ];

    public const STATUS_OPTIONS = [
        WebhookDelivery::STATUS_DELIVERED => 'Delivered',
        WebhookDelivery::STATUS_FAILED => 'Failed',
    ];

    public string $uiState = 'normal';

    public string $search = '';

    public string $eventFilter = 'all';

    public string $statusFilter = 'all';

    public ?DateRange $range = null;

    public ?int $payloadId = null;

    public bool $payloadModal = false;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function endpoint(): ?WebhookEndpoint
    {
        return WebhookEndpoint::query()->first();
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load webhook deliveries. Please try again.";
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->eventFilter !== 'all'
            || $this->statusFilter !== 'all'
            || ($this->range?->hasStart() ?? false);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->eventFilter = 'all';
        $this->statusFilter = 'all';
        $this->range = null;
        $this->resetPage();
    }

    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null || $this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        $like = $this->search !== '' ? '%'.addcslashes($this->search, '%_\\').'%' : null;
        [$from, $to] = $this->range?->hasStart() && $this->range->hasEnd()
            ? [$this->range->start()->startOfDay(), $this->range->end()->endOfDay()]
            : [null, null];

        return WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->when($this->eventFilter !== 'all', fn ($query) => $query->where('event', $this->eventFilter))
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($like !== null, fn ($query) => $query->where(function ($q) use ($like) {
                $q->where('payload->data->customer_reference', 'like', $like)
                    ->orWhere('payload->data->tx_hash', 'like', $like);
            }))
            ->when($from !== null, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
            ->latest('id')
            ->paginate(10);
    }

    /**
     * @return Collection<string, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        $references = $this->deliveries->getCollection()
            ->map(fn (WebhookDelivery $delivery): ?string => $delivery->payload['data']['customer_reference'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($references->isEmpty()) {
            return collect();
        }

        return Customer::query()
            ->whereIn('customer_reference', $references)
            ->get()
            ->keyBy('customer_reference');
    }

    #[Computed]
    public function payloadDelivery(): ?WebhookDelivery
    {
        $endpoint = $this->endpoint();

        if ($endpoint === null || $this->payloadId === null) {
            return null;
        }

        return WebhookDelivery::query()
            ->where('webhook_endpoint_id', $endpoint->id)
            ->find($this->payloadId);
    }

    public function showPayload(int $deliveryId): void
    {
        $endpoint = $this->endpoint();

        $delivery = $endpoint === null
            ? null
            : WebhookDelivery::query()
                ->where('webhook_endpoint_id', $endpoint->id)
                ->find($deliveryId);

        if ($delivery === null) {
            return;
        }

        $this->payloadId = $delivery->id;
        $this->payloadModal = true;
    }

    public function redeliver(int $deliveryId): void
    {
        $endpoint = $this->endpoint();

        $delivery = $endpoint === null
            ? null
            : WebhookDelivery::query()
                ->where('webhook_endpoint_id', $endpoint->id)
                ->find($deliveryId);

        if ($delivery === null || $delivery->status !== WebhookDelivery::STATUS_FAILED) {
            return;
        }

        DeliverWebhook::dispatch($endpoint->id, $delivery->event, $delivery->payload ?? []);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRange(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function retry(): void
    {
        $this->uiState = 'normal';
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.webhooks');
    }
}
