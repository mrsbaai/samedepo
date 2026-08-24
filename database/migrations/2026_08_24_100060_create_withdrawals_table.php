<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
            $table->decimal('gross_amount', 20, 8);
            $table->decimal('network_fee', 20, 8)->nullable();
            $table->decimal('amount_sent', 20, 8)->nullable();
            $table->string('destination_address');
            $table->enum('mode', ['instant', 'approval']);
            $table->enum('status', ['pending', 'approved', 'denied', 'cancelled', 'sent']);
            $table->string('tx_hash')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
