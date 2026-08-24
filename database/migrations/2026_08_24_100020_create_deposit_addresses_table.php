<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
            $table->string('address');
            $table->timestamps();

            $table->unique(['customer_id', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_addresses');
    }
};
