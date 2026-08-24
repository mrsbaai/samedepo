<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Environment'])]
class EnvironmentEditor extends Component
{
    public string $content = '';

    public string $savedContent = '';

    public function mount(): void
    {
        $this->content = $this->savedContent = $this->readEnvFile();
    }

    public function updatedContent(): void
    {
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->content = $this->savedContent;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $this->validateLines();

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        File::put($this->envPath(), rtrim($this->content, "\n")."\n");

        Artisan::call('config:clear');
        Artisan::call('config:cache');

        $this->content = $this->savedContent = $this->readEnvFile();

        session()->flash('status', 'Environment file saved and configuration cache refreshed.');
    }

    public function render(): mixed
    {
        return view('livewire.admin.environment-editor');
    }

    private function validateLines(): void
    {
        foreach (explode("\n", $this->content) as $number => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=.*$/', $line)) {
                $this->addError('content', 'Line '.($number + 1).' is not valid: expected KEY=VALUE or a comment starting with "#".');

                return;
            }
        }
    }

    private function readEnvFile(): string
    {
        $content = File::exists($this->envPath()) ? File::get($this->envPath()) : '';

        return rtrim($content, "\n");
    }

    private function envPath(): string
    {
        return app()->bound('env-editor.path')
            ? app('env-editor.path')
            : app()->environmentFilePath();
    }
}
