<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.dashboard.layout', ['title' => 'Log Viewer'])]
class LogViewer extends Component
{
    public ?string $selectedFile = null;

    public function selectFile(string $file): void
    {
        if (! $this->isValidLogFile($file)) {
            $this->selectedFile = null;

            return;
        }

        $this->selectedFile = $file;
    }

    public function deleteFile(string $file): void
    {
        if (! $this->isValidLogFile($file)) {
            return;
        }

        File::delete($this->filePath($file));

        if ($this->selectedFile === $file) {
            $this->selectedFile = null;
        }

        $this->dispatch('log-deleted', file: $file);
    }

    public function copyEntry(int $index): void
    {
        $entries = $this->entries;

        if (! isset($entries[$index])) {
            return;
        }

        $this->dispatch('copy-to-clipboard', text: $entries[$index]['body']);
    }

    public function render(): mixed
    {
        return view('livewire.admin.log-viewer');
    }

    /** @return array<int, array{timestamp: string, channel: string, level: string, message: string, body: string, exception: ?string, summary: string}> */
    #[Computed]
    public function entries(): array
    {
        if ($this->selectedFile === null || ! $this->isValidLogFile($this->selectedFile)) {
            return [];
        }

        return $this->parseEntries(File::get($this->filePath($this->selectedFile)));
    }

    /** @return array<int, array{timestamp: string, channel: string, level: string, message: string, body: string, exception: ?string, summary: string}> */
    private function parseEntries(string $content): array
    {
        $lines = explode("\n", $content);
        $entries = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s*[.:]\s*(.*)$/', $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $levelParts = $this->splitLevel($matches[2]);
                $message = $matches[3];
                $exception = $this->extractException($message);

                $current = [
                    'timestamp' => $matches[1],
                    'channel' => $levelParts['channel'],
                    'level' => $levelParts['level'],
                    'message' => $message,
                    'body' => $line,
                    'exception' => $exception,
                    'summary' => $this->extractSummary($message, $exception),
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            $current['body'] .= "\n".$line;
            $current['message'] .= "\n".$line;
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return array_reverse($entries);
    }

    /** @return array{channel: string, level: string} */
    private function splitLevel(string $token): array
    {
        if (str_contains($token, '.')) {
            [$channel, $level] = explode('.', $token, 2);

            return ['channel' => $channel, 'level' => $level];
        }

        return ['channel' => '', 'level' => $token];
    }

    private function extractException(string $message): ?string
    {
        if (preg_match('/\b([A-Za-z_][A-Za-z0-9_]*(?:Exception|Error))\b/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractSummary(string $message, ?string $exception): string
    {
        $text = $message;

        if ($exception !== null) {
            $text = preg_replace('/'.preg_quote($exception, '/').'\s*[:\-]?\s*/', '', $text, 1) ?? $text;
        }

        $text = trim($text);
        $text = (string) preg_replace('/\s+/', ' ', $text);

        $firstLine = explode("\n", $text)[0];

        return Str($firstLine)->limit(120, preserveWords: true)->toString();
    }

    private function isValidLogFile(string $file): bool
    {
        $path = $this->filePath($file);

        return str_ends_with($file, '.log')
            && File::exists($path)
            && ! str_contains($file, '..')
            && File::isFile($path);
    }

    private function filePath(string $file): string
    {
        return $this->logsPath().DIRECTORY_SEPARATOR.$file;
    }

    private function logsPath(): string
    {
        return app()->bound('log-viewer.path')
            ? app('log-viewer.path')
            : storage_path('logs');
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function files(): array
    {
        $path = $this->logsPath();

        if (! File::isDirectory($path)) {
            return [];
        }

        $files = File::files($path);
        $files = array_filter($files, fn ($file) => str_ends_with($file->getFilename(), '.log'));
        usort($files, fn ($a, $b) => $b->getMTime() <=> $a->getMTime());

        return array_values(array_map(fn ($file) => $file->getFilename(), $files));
    }
}
