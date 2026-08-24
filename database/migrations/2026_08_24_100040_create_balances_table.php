<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
            $table->decimal('amount', 20, 8)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balances');
    }
};
