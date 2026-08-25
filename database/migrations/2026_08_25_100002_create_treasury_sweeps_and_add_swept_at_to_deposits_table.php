<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treasury_sweeps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained('deposits')->cascadeOnDelete();
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20']);
            $table->decimal('amount', 20, 8);
            $table->string('tx_hash')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('deposits', function (Blueprint $table) {
            $table->timestamp('swept_at')->nullable()->after('credited_at');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('swept_at');
        });

        Schema::dropIfExists('treasury_sweeps');
    }
};
