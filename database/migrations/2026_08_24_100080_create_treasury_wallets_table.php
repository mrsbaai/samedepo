<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_wallets', function (Blueprint $table) {
            $table->id();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20'])->unique();
            $table->string('address');
            $table->decimal('available_funds', 20, 8)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_wallets');
    }
};
