<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('network');
            $table->string('destination_address');
            $table->decimal('amount', 20, 8);
            $table->decimal('network_fee', 20, 8)->nullable();
            $table->string('tx_hash')->nullable();
            $table->string('status')->default('pending');
            $table->string('error_message')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('treasury_payouts');
    }
};
