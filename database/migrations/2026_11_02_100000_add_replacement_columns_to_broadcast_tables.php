<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['treasury_sweeps', 'withdrawals', 'gas_topups', 'treasury_payouts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->json('replaced_tx_hashes')->nullable();
                $blueprint->timestamp('last_broadcast_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['treasury_sweeps', 'withdrawals', 'gas_topups', 'treasury_payouts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['replaced_tx_hashes', 'last_broadcast_at']);
            });
        }
    }
};
