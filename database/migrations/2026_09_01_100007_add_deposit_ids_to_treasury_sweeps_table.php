<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table): void {
            $table->json('deposit_ids')->nullable()->after('deposit_address_id');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_sweeps', function (Blueprint $table): void {
            $table->dropColumn('deposit_ids');
        });
    }
};
