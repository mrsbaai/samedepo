<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_policies', function (Blueprint $table) {
            $table->string('energy_mode')->default('burn')->after('manual_paused');
            $table->unsignedInteger('rent_max_price_sun')->default(90)->after('energy_mode');
            $table->unsignedInteger('rent_duration_sec')->default(3600)->after('rent_max_price_sun');
            $table->decimal('rent_float_alert_trx', 20, 8)->default(20)->after('rent_duration_sec');
        });
    }

    public function down(): void
    {
        Schema::table('gas_policies', function (Blueprint $table) {
            $table->dropColumn(['energy_mode', 'rent_max_price_sun', 'rent_duration_sec', 'rent_float_alert_trx']);
        });
    }
};
