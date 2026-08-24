<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usd_valuations', function (Blueprint $table) {
            $table->id();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20'])->unique();
            $table->decimal('conversion_value', 16, 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usd_valuations');
    }
};
