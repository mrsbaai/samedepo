<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\SupportIdentity;
use App\Models\SupportSetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('components.dashboard.layout', ['title' => 'Support Settings'])]
class SupportSettings extends Component
{
    use WithFileUploads;

    public bool $isModalOpen = false;

    public ?string $editingRole = null;

    #[Validate('nullable|string|max:255')]
    public string $editingName = '';

    public ?string $editingAvatar = null;

    #[Validate('nullable|image|max:5120')]
    public $uploadedAvatar = null;

    #[Validate('nullable|string|max:5000')]
    public string $specialInstructions = '';

    #[Validate('nullable|string|max:2000')]
    public string $serviceDescription = '';

    #[Validate('nullable|string|max:2000')]
    public string $serviceUseCase = '';

    public function mount(): void
    {
        foreach (SupportIdentity::ROLES as $role) {
            SupportIdentity::forRole($role);
        }

        $settings = SupportSetting::current();
        $this->specialInstructions = $settings->special_instructions ?? '';
        $this->serviceDescription = $settings->service_description ?? '';
        $this->serviceUseCase = $settings->service_use_case ?? '';
    }

    public function openIdentityModal(string $role): void
    {
        $identity = SupportIdentity::forRole($role);
        $this->editingRole = $role;
        $this->editingName = $identity->name ?? '';
        $this->editingAvatar = $identity->avatar;
        $this->isModalOpen = true;
    }

    public function closeIdentityModal(): void
    {
        $this->reset('isModalOpen', 'editingRole', 'editingName', 'editingAvatar', 'uploadedAvatar');
    }

    public function selectAvatar(string $avatar): void
    {
        $this->editingAvatar = $avatar;
    }

    public function saveIdentity(): void
    {
        $this->validate();

        $avatar = $this->editingAvatar;

        if ($this->uploadedAvatar) {
            $avatar = $this->uploadedAvatar->store('support-agents', 'public');
        }

        SupportIdentity::query()->updateOrCreate(
            ['role' => $this->editingRole],
            [
                'name' => $this->editingName ?: null,
                'avatar' => $avatar,
            ]
        );

        $this->closeIdentityModal();
        $this->dispatch('support-avatar-updated');
    }

    public function saveSpecialInstructions(): void
    {
        $this->validateOnly('specialInstructions');

        $setting = SupportSetting::current();
        $setting->special_instructions = $this->specialInstructions;
        $setting->save();
    }

    public function saveServiceContext(): void
    {
        $this->validate([
            'serviceDescription' => 'nullable|string|max:2000',
            'serviceUseCase' => 'nullable|string|max:2000',
        ]);

        $setting = SupportSetting::current();
        $setting->service_description = $this->serviceDescription ?: null;
        $setting->service_use_case = $this->serviceUseCase ?: null;
        $setting->save();
    }

    public function render(): mixed
    {
        return view('livewire.admin.support-settings', [
            'identities' => SupportIdentity::query()->whereIn('role', SupportIdentity::ROLES)->get(),
            'avatars' => SupportIdentity::availableAvatars(),
        ]);
    }
}
