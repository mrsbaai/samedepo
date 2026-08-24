<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\ApiKey;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.dashboard.layout', ['title' => 'API Keys'])]
class ApiKeys extends Component
{
    use WithPagination;

    public string $uiState = 'normal';

    public string $newKeyName = '';

    public ?string $revealedKey = null;

    public string $successMessage = '';

    public ?int $selectedKeyId = null;

    public bool $showRevokeModal = false;

    public bool $showReplaceModal = false;

    public function mount(): void
    {
        $this->uiState = request()->query('state', 'normal');
    }

    #[Computed]
    public function errorMessage(): ?string
    {
        if ($this->uiState !== 'error') {
            return null;
        }

        return "Couldn't load API keys. The key service returned an error.";
    }

    #[Computed]
    public function selectedKey(): ?ApiKey
    {
        if ($this->selectedKeyId === null) {
            return null;
        }

        return ApiKey::query()->find($this->selectedKeyId);
    }

    #[Computed]
    public function keys(): LengthAwarePaginator
    {
        if ($this->uiState === 'error') {
            return new LengthAwarePaginator([], 0, 10, 1, ['path' => request()->url()]);
        }

        return ApiKey::query()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    public function generate(): void
    {
        $validated = $this->validate([
            'newKeyName' => ['required', 'string', 'max:255'],
        ]);

        $token = 'sm_api_'.bin2hex(random_bytes(32));

        ApiKey::create([
            'user_id' => Auth::id(),
            'name' => $validated['newKeyName'],
            'key_hash' => Hash::make($token),
            'status' => 'active',
        ]);

        $this->revealedKey = $token;
        $this->successMessage = "API key generated for {$validated['newKeyName']}. Copy it now — you won't see it again.";
        $this->newKeyName = '';
        $this->resetPage();
    }

    public function confirmRevoke(int $id): void
    {
        $this->selectedKeyId = $id;
        $this->showRevokeModal = true;
    }

    public function revoke(): void
    {
        $key = ApiKey::query()
            ->where('status', 'active')
            ->find($this->selectedKeyId);

        if ($key) {
            $key->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);

            $this->successMessage = "API key for {$key->name} revoked. It can no longer authenticate requests.";
        }

        $this->showRevokeModal = false;
        $this->selectedKeyId = null;
        $this->revealedKey = null;
    }

    public function confirmReplace(int $id): void
    {
        $this->selectedKeyId = $id;
        $this->showReplaceModal = true;
    }

    public function replace(): void
    {
        $key = ApiKey::query()
            ->where('status', 'active')
            ->find($this->selectedKeyId);

        if (! $key) {
            $this->showReplaceModal = false;
            $this->selectedKeyId = null;

            return;
        }

        $name = $key->name;

        $key->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $token = 'sm_api_'.bin2hex(random_bytes(32));

        ApiKey::create([
            'user_id' => Auth::id(),
            'name' => $name,
            'key_hash' => Hash::make($token),
            'status' => 'active',
        ]);

        $this->revealedKey = $token;
        $this->successMessage = "API key replaced for {$name}. The old key no longer works. Copy the new key now.";
        $this->showReplaceModal = false;
        $this->selectedKeyId = null;
        $this->resetPage();
    }

    public function retry(): void
    {
        $this->uiState = request()->query('state', 'normal');
        $this->successMessage = '';
        $this->revealedKey = null;
    }

    public function render(): mixed
    {
        return view('livewire.dashboard.api-keys');
    }
}
