<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->text('special_instructions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn('special_instructions');
        });
    }
};
