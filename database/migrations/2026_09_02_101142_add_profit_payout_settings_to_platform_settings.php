<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('profit_address_bitcoin', 128)->nullable();
            $table->string('profit_address_usdt_trc20', 128)->nullable();
            $table->string('profit_address_usdt_erc20', 128)->nullable();
            $table->decimal('profit_payout_warn_fee_percent', 5, 2)->default(1.00);
            $table->decimal('profit_payout_block_fee_percent', 5, 2)->default(5.00);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'profit_address_bitcoin',
                'profit_address_usdt_trc20',
                'profit_address_usdt_erc20',
                'profit_payout_warn_fee_percent',
                'profit_payout_block_fee_percent',
            ]);
        });
    }
};
