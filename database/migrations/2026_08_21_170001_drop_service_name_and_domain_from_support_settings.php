<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn(['service_name', 'domain_name']);
        });
    }

    public function down(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->string('service_name')->nullable();
            $table->string('domain_name')->nullable();
        });
    }
};
