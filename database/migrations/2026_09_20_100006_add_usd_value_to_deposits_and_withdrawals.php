<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->decimal('usd_value', 18, 2)->nullable()->after('credited_amount');
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->decimal('usd_value', 18, 2)->nullable()->after('amount_sent');
        });

        $rates = DB::table('usd_valuations')->pluck('conversion_value', 'network');

        foreach ($rates as $network => $rate) {
            DB::table('deposits')
                ->where('network', $network)
                ->where('status', 'credited')
                ->whereNotNull('credited_amount')
                ->chunkById(500, function ($deposits) use ($rate): void {
                    foreach ($deposits as $deposit) {
                        DB::table('deposits')->where('id', $deposit->id)
                            ->update(['usd_value' => round((float) $deposit->credited_amount * (float) $rate, 2)]);
                    }
                });

            DB::table('withdrawals')
                ->where('network', $network)
                ->where('status', 'sent')
                ->whereNotNull('amount_sent')
                ->chunkById(500, function ($withdrawals) use ($rate): void {
                    foreach ($withdrawals as $withdrawal) {
                        DB::table('withdrawals')->where('id', $withdrawal->id)
                            ->update(['usd_value' => round((float) $withdrawal->amount_sent * (float) $rate, 2)]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table): void {
            $table->dropColumn('usd_value');
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropColumn('usd_value');
        });
    }
};
