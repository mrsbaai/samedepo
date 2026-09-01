<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->foreignId('deposit_id')->nullable()->change();
            $table->foreignId('deposit_address_id')->nullable()->after('deposit_id')->constrained('deposit_addresses')->nullOnDelete();
            $table->timestamp('fee_recovered_at')->nullable()->after('confirmed_at');
            $table->foreignId('recovered_withdrawal_id')->nullable()->after('fee_recovered_at')->constrained('withdrawals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->dropForeign(['recovered_withdrawal_id']);
            $table->dropColumn(['deposit_address_id', 'fee_recovered_at', 'recovered_withdrawal_id']);
            $table->foreignId('deposit_id')->nullable(false)->change();
        });
    }
};
