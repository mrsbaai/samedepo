<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->chunkById(100, function ($endpoints): void {
            foreach ($endpoints as $endpoint) {
                $events = json_decode((string) $endpoint->enabled_events, true);
                $events = is_array($events) ? $events : [];

                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update([
                    'enabled_events' => json_encode(array_values(array_unique([...$events, 'deposit.pending', 'deposit.credited'])), JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('webhook_endpoints')->orderBy('id')->chunkById(100, function ($endpoints): void {
            foreach ($endpoints as $endpoint) {
                $events = json_decode((string) $endpoint->enabled_events, true);
                $events = is_array($events) ? $events : [];

                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update([
                    'enabled_events' => json_encode(array_values(array_diff($events, ['deposit.pending'])), JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }
};
