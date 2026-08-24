<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Dashboard'])]
class UserDashboard extends Component
{
    public function render(): mixed
    {
        return view('livewire.dashboard.user-dashboard');
    }
}
