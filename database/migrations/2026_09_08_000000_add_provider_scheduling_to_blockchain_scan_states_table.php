<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blockchain_scan_states', function (Blueprint $table) {
            $table->unsignedBigInteger('last_scanned_block')->nullable()->change();
            $table->timestamp('next_scan_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
        });
    }

    public function down(): void
    {
        DB::table('blockchain_scan_states')->whereNull('last_scanned_block')->update(['last_scanned_block' => 0]);

        Schema::table('blockchain_scan_states', function (Blueprint $table) {
            $table->dropColumn(['next_scan_at', 'cooldown_until', 'consecutive_failures']);
            $table->unsignedBigInteger('last_scanned_block')->nullable(false)->change();
        });
    }
};
