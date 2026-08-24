<?php

declare(strict_types=1);

namespace App\Livewire\Authentication;

use App\Actions\Authentication\RequestAccountDeletion;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.authentication.layout', ['title' => 'Delete account', 'description' => 'Request account deletion. You can recover your account for 30 days.'])]
class DeleteAccount extends Component
{
    public string $currentPassword = '';

    public bool $confirmDeletion = false;

    public function requestDeletion(RequestAccountDeletion $requestAccountDeletion): void
    {
        $validated = $this->validate();

        /** @var User $user */
        $user = auth()->user();

        auth()->logout();
        session()->invalidate();

        $requestAccountDeletion->execute($user, request());

        session()->flash('status', 'Your account deletion request has been received. Check your email for recovery options.');

        $this->redirect('/signin', navigate: true);
    }

    protected function rules(): array
    {
        return [
            'currentPassword' => ['required', 'current_password'],
            'confirmDeletion' => ['accepted'],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.authentication.delete-account');
    }
}
