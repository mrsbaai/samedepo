<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORIGINAL_STATUSES = ['detected', 'pending', 'credited', 'below_minimum', 'forfeited'];

    private const NEW_STATUSES = ['detected', 'pending', 'credited', 'below_minimum', 'forfeited', 'reversed'];

    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', self::NEW_STATUSES)->change();
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', array_values(array_unique([...self::ORIGINAL_STATUSES, ...self::NEW_STATUSES])))->change();
        });

        DB::table('deposits')->where('status', 'reversed')->update(['status' => 'forfeited']);

        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('status', self::ORIGINAL_STATUSES)->change();
        });
    }
};
