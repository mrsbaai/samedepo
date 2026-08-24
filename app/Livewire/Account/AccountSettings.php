<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Security'])]
class AccountSettings extends Component
{
    public function render(): mixed
    {
        return view('livewire.account.account-settings');
    }
}
