<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_policies', function (Blueprint $table): void {
            $table->unsignedSmallInteger('rent_energy_headroom_percent')->default(10)->after('rent_duration_sec');
        });
    }

    public function down(): void
    {
        Schema::table('gas_policies', function (Blueprint $table): void {
            $table->dropColumn('rent_energy_headroom_percent');
        });
    }
};
