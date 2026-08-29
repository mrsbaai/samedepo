<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Security\Models\ForbiddenEvent;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Forbidden Log'])]
class ForbiddenLog extends Component
{
    use WithPagination;

    #[Url]
    public string $period = '24h';

    #[Url]
    public string $source = '';

    #[Url]
    public string $reason = '';

    #[Url]
    public string $ip = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'source', 'reason', 'ip'], true)) {
            $this->resetPage();
        }
    }

    public function render(): mixed
    {
        $since = match ($this->period) {
            '1h' => now()->subHour(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        $events = ForbiddenEvent::query()
            ->where('created_at', '>=', $since)
            ->when($this->source !== '', fn ($query) => $query->where('source', $this->source))
            ->when($this->reason !== '', fn ($query) => $query->where('reason', $this->reason))
            ->when($this->ip !== '', fn ($query) => $query->where('ip_address', 'like', "%{$this->ip}%"))
            ->latest()
            ->paginate(15);

        $bySource = ForbiddenEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->orderByDesc('total')
            ->pluck('total', 'source');

        $byReason = ForbiddenEvent::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('reason')
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason');

        $sources = ForbiddenEvent::query()->distinct()->orderBy('source')->pluck('source');
        $reasons = ForbiddenEvent::query()->whereNotNull('reason')->distinct()->orderBy('reason')->pluck('reason');

        return view('livewire.admin.forbidden-log', [
            'events' => $events,
            'bySource' => $bySource,
            'byReason' => $byReason,
            'totalToday' => ForbiddenEvent::query()->whereDate('created_at', today())->count(),
            'totalPeriod' => ForbiddenEvent::query()->where('created_at', '>=', $since)->count(),
            'sources' => $sources,
            'reasons' => $reasons,
        ]);
    }
}
