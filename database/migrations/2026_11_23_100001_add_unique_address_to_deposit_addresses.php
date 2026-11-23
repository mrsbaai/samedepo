<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $shared = DB::table('deposit_addresses')
            ->select('network', 'address')
            ->groupBy('network', 'address')
            ->havingRaw('COUNT(DISTINCT customer_id) > 1')
            ->exists();

        if ($shared) {
            throw new RuntimeException('Run php artisan deposits:repair-shared-addresses --apply first');
        }

        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->unique(['network', 'address']);
        });
    }

    public function down(): void
    {
        Schema::table('deposit_addresses', function (Blueprint $table) {
            $table->dropUnique(['network', 'address']);
        });
    }
};
