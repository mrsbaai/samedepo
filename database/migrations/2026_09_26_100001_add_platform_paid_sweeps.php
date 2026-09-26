<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->boolean('platform_paid')->default(false)->after('status');
        });

        Schema::table('gas_topups', function (Blueprint $table) {
            $table->foreignId('treasury_sweep_id')->nullable()->after('treasury_wallet_id')->constrained('treasury_sweeps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gas_topups', function (Blueprint $table) {
            $table->dropForeign(['treasury_sweep_id']);
            $table->dropColumn('treasury_sweep_id');
        });

        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->dropColumn('platform_paid');
        });
    }
};
