<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_wallets', function (Blueprint $table): void {
            $table->unsignedInteger('derivation_index')->default(0)->after('network');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_wallets', function (Blueprint $table): void {
            $table->dropColumn('derivation_index');
        });
    }
};
