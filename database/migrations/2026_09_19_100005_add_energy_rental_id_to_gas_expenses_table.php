<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_expenses', function (Blueprint $table) {
            $table->foreignId('energy_rental_id')->nullable()->after('gas_topup_id')
                ->unique()
                ->constrained('energy_rentals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gas_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('energy_rental_id');
        });
    }
};
