<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->foreignId('piggybacked_on_sweep_id')->nullable()->after('deposit_address_id')
                ->constrained('treasury_sweeps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('piggybacked_on_sweep_id');
        });
    }
};
