<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_wallets', function (Blueprint $table) {
            $table->decimal('rental_balance', 20, 8)->nullable()->after('bandwidth');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_wallets', function (Blueprint $table) {
            $table->dropColumn('rental_balance');
        });
    }
};
