<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class DownloadSupportAvatars extends Command
{
    protected $signature = 'support:download-avatars';

    protected $description = 'Download the full support agent avatar pool from the free-user-avatars repo.';

    private const LIST_URL = 'https://api.github.com/repos/BaseMax/free-user-avatars/contents/png';

    private const BASE_URL = 'https://raw.githubusercontent.com/BaseMax/free-user-avatars/main/png/';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $disk->makeDirectory('support-agents');

        $response = Http::get(self::LIST_URL);

        if (! $response->successful()) {
            $this->error("Failed to list avatars (status {$response->status()}).");

            return self::FAILURE;
        }

        $files = collect($response->json())
            ->where('type', 'file')
            ->where(fn (array $file) => str_ends_with(strtolower($file['name'] ?? ''), '.png'))
            ->pluck('name');

        $this->info("Found {$files->count()} avatars.");

        foreach ($files as $filename) {
            $url = self::BASE_URL.$filename;
            $path = 'support-agents/'.$filename;

            if ($disk->exists($path)) {
                $this->info("Skipped {$filename} (already exists).");

                continue;
            }

            $download = Http::get($url);

            if ($download->successful()) {
                $disk->put($path, $download->body());
                $this->info("Downloaded {$filename}.");
            } else {
                $this->error("Failed to download {$filename} (status {$download->status()}).");
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
