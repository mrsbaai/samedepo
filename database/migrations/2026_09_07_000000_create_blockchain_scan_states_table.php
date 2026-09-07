<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blockchain_scan_states', function (Blueprint $table) {
            $table->id();
            $table->string('network')->unique();
            $table->unsignedBigInteger('last_scanned_block');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_scan_states');
    }
};
