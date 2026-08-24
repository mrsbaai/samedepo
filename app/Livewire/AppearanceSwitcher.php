<?php

namespace App\Livewire;

use Illuminate\View\View;
use Livewire\Component;

class AppearanceSwitcher extends Component
{
    public string $appearance = 'dark';

    public function mount(): void
    {
        $appearance = auth()->user()?->appearance;

        $this->appearance = in_array($appearance, ['dark', 'light'], true) ? $appearance : 'dark';
    }

    public function toggle(): void
    {
        $this->appearance = $this->appearance === 'dark' ? 'light' : 'dark';

        auth()->user()?->update(['appearance' => $this->appearance]);
    }

    public function render(): View
    {
        return view('livewire.appearance-switcher');
    }
}
