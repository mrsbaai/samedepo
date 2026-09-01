<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usd_valuations', function (Blueprint $table) {
            $table->dropUnique(['network']);
            $table->string('network', 64)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('usd_valuations', function (Blueprint $table) {
            $table->dropUnique(['network']);
            $table->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20'])->unique()->change();
        });
    }
};
