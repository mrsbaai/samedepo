<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->unsignedBigInteger('derivation_index')->default(0)->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->dropColumn('derivation_index');
        });
    }
};
