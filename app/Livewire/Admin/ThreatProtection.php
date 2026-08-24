<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Security\Blocklist\DeviceBlocklist;
use App\Security\Blocklist\IpBlocklist;
use App\Security\Models\DetectorSetting;
use App\Security\Models\SecurityBlock;
use App\Security\Models\ThreatEvent;
use App\Security\ThreatDetector;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'Threat Protection'])]
class ThreatProtection extends Component
{
    use WithPagination;

    #[Url]
    public string $severity = '';

    #[Url]
    public string $detector = '';

    #[Url]
    public string $ip = '';

    #[Url]
    public string $period = '24h';

    /** Bound to the detail modal; Livewire sets it to false on close. */
    public $selectedEventId = null;

    public string $blockType = 'ip';

    public string $blockValue = '';

    public string $blockReason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['severity', 'detector', 'ip', 'period'], true)) {
            $this->resetPage();
        }
    }

    public function toggleDetector(string $key): void
    {
        $setting = DetectorSetting::query()->firstOrCreate(['key' => $key], ['enabled' => true]);
        $setting->update(['enabled' => ! $setting->enabled]);
    }

    public function showEvent(int $eventId): void
    {
        $this->selectedEventId = $eventId;
    }

    public function closeEvent(): void
    {
        $this->selectedEventId = null;
    }

    public function unblockIp(string $value): void
    {
        IpBlocklist::unblock($value);
    }

    public function unblockDevice(string $value): void
    {
        DeviceBlocklist::unblock($value);
    }

    public function block(): void
    {
        $this->validate([
            'blockType' => 'required|in:ip,device',
            'blockValue' => 'required|string|max:255',
            'blockReason' => 'nullable|string|max:255',
        ]);

        $blocklist = $this->blockType === 'ip' ? IpBlocklist::class : DeviceBlocklist::class;
        $blocklist::block(trim($this->blockValue), $this->blockReason ?: 'Manually blocked', 'manual', auth()->id());

        $this->reset('blockValue', 'blockReason');
    }

    public function render(): mixed
    {
        $since = match ($this->period) {
            '1h' => now()->subHour(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subDay(),
        };

        $events = ThreatEvent::query()
            ->where('created_at', '>=', $since)
            ->when($this->severity !== '', function ($query): void {
                $ranges = ['critical' => [9, 10], 'high' => [7, 8], 'medium' => [5, 6], 'low' => [1, 4]];
                [$min, $max] = $ranges[$this->severity] ?? [1, 10];
                $query->whereBetween('severity', [$min, $max]);
            })
            ->when($this->detector !== '', fn ($query) => $query->where('detector', $this->detector))
            ->when($this->ip !== '', fn ($query) => $query->where('ip_address', 'like', "%{$this->ip}%"))
            ->latest()
            ->paginate(15);

        $breakdown = ThreatEvent::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('detector, count(*) as total')
            ->groupBy('detector')
            ->orderByDesc('total')
            ->pluck('total', 'detector');

        return view('livewire.admin.threat-protection', [
            'events' => $events,
            'breakdown' => $breakdown,
            'selectedEvent' => $this->selectedEventId ? ThreatEvent::query()->find($this->selectedEventId) : null,
            'threatsToday' => ThreatEvent::query()->whereDate('created_at', today())->count(),
            'blockedIps' => SecurityBlock::query()->where('type', 'ip')->count(),
            'blockedDevices' => SecurityBlock::query()->where('type', 'device')->count(),
            'activeAttacks' => ThreatEvent::query()->where('created_at', '>=', now()->subHour())->distinct('ip_address')->count('ip_address'),
            'detectors' => collect(ThreatDetector::DETECTORS)
                ->map(fn (string $class) => class_basename($class))
                ->map(fn (string $key) => ['key' => $key, 'enabled' => DetectorSetting::isEnabled($key)]),
            'blocks' => SecurityBlock::query()->latest()->limit(50)->get(),
            'ipBlocked' => fn (?string $value) => IpBlocklist::isBlocked($value),
            'deviceBlocked' => fn (?string $value) => DeviceBlocklist::isBlocked($value),
        ]);
    }
}
