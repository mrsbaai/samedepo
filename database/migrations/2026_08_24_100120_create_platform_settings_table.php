<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('global_deposit_fee_percent', 5, 2);
            $table->enum('default_withdrawal_mode', ['instant', 'approval']);
            $table->decimal('min_deposit_bitcoin', 20, 8);
            $table->decimal('min_deposit_usdt_trc20', 20, 8);
            $table->decimal('min_deposit_usdt_erc20', 20, 8);
            $table->decimal('withdrawal_min_usd_bitcoin', 10, 2);
            $table->decimal('withdrawal_min_usd_usdt_trc20', 10, 2);
            $table->decimal('withdrawal_min_usd_usdt_erc20', 10, 2);
            $table->integer('confirmations_bitcoin');
            $table->integer('confirmations_usdt_trc20');
            $table->integer('confirmations_usdt_erc20');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
