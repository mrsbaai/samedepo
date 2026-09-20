<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_settings', function (Blueprint $table): void {
            $table->boolean('enabled')->nullable()->after('profit_address');
        });
    }

    public function down(): void
    {
        Schema::table('network_settings', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};
