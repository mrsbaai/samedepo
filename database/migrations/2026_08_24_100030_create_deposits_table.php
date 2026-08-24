<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_address_id')->constrained('deposit_addresses')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
            $table->string('tx_hash');
            $table->decimal('gross_amount', 20, 8);
            $table->decimal('fee_amount', 20, 8)->nullable();
            $table->decimal('credited_amount', 20, 8)->nullable();
            $table->integer('confirmation_count')->default(0);
            $table->enum('status', ['detected', 'pending', 'credited', 'ignored']);
            $table->timestamp('detected_at');
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
    }
};
