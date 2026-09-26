<?php

use App\Support\Network;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_STATUSES = ['detected', 'pending', 'credited', 'ignored'];

    private const NEW_STATUSES = ['detected', 'pending', 'credited', 'below_minimum', 'forfeited'];

    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('minimum_amount', 24, 8)->nullable()->after('gross_amount');
            $table->timestamp('expires_at')->nullable()->after('credited_at');
            $table->timestamp('forfeited_at')->nullable()->after('expires_at');
            $table->foreignId('manually_credited_by')->nullable()->after('forfeited_at')->constrained('users')->nullOnDelete();
            $table->timestamp('manually_credited_at')->nullable()->after('manually_credited_by');
        });

        // Widen the enum first so the conversion below is a legal value on MySQL.
        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', array_values(array_unique([...self::ORIGINAL_STATUSES, ...self::NEW_STATUSES])))->change();
        });

        $expiresAt = now()->addDays((int) config('blockchain.short_payment_expiry_days', 7));

        DB::table('deposits')->where('status', 'ignored')->distinct()->pluck('network')
            ->each(function (string $network) use ($expiresAt): void {
                $minimum = DB::table('network_settings')->where('network', $network)->value('min_deposit')
                    ?? (Network::exists($network) ? (Network::get($network)['settings']['min_deposit'] ?? '0.00000000') : '0.00000000');

                DB::table('deposits')
                    ->where('status', 'ignored')
                    ->where('network', $network)
                    ->update([
                        'status' => 'below_minimum',
                        'minimum_amount' => $minimum,
                        'expires_at' => $expiresAt,
                    ]);
            });

        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', self::NEW_STATUSES)->change();
        });

        DB::table('webhook_endpoints')->orderBy('id')->chunkById(100, function ($endpoints): void {
            foreach ($endpoints as $endpoint) {
                $events = json_decode((string) $endpoint->enabled_events, true);
                $events = is_array($events) ? $events : [];

                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update([
                    'enabled_events' => json_encode(array_values(array_unique([...$events, 'deposit.below_minimum', 'deposit.forfeited'])), JSON_THROW_ON_ERROR),
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
                    'enabled_events' => json_encode(array_values(array_diff($events, ['deposit.below_minimum', 'deposit.forfeited'])), JSON_THROW_ON_ERROR),
                ]);
            }
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', array_values(array_unique([...self::ORIGINAL_STATUSES, ...self::NEW_STATUSES])))->change();
        });

        DB::table('deposits')->whereIn('status', ['below_minimum', 'forfeited'])->update(['status' => 'ignored']);

        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', self::ORIGINAL_STATUSES)->change();
            $table->dropForeign(['manually_credited_by']);
            $table->dropColumn(['minimum_amount', 'expires_at', 'forfeited_at', 'manually_credited_by', 'manually_credited_at']);
        });
    }
};
