<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('sweep_min_usd_bitcoin', 10, 2)->default(200.00)->after('withdrawal_min_usd_usdt_erc20');
            $table->decimal('sweep_min_usd_usdt_trc20', 10, 2)->default(25.00)->after('sweep_min_usd_bitcoin');
            $table->decimal('sweep_min_usd_usdt_erc20', 10, 2)->default(300.00)->after('sweep_min_usd_usdt_trc20');
            $table->unsignedInteger('sweep_max_age_days')->default(30)->after('sweep_min_usd_usdt_erc20');
            $table->decimal('withdrawal_fee_buffer_percent', 5, 2)->default(20.00)->after('sweep_max_age_days');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sweep_min_usd_bitcoin',
                'sweep_min_usd_usdt_trc20',
                'sweep_min_usd_usdt_erc20',
                'sweep_max_age_days',
                'withdrawal_fee_buffer_percent',
            ]);
        });
    }
};
