<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->string('service_name')->nullable()->after('special_instructions');
            $table->string('domain_name')->nullable()->after('service_name');
            $table->text('service_description')->nullable()->after('domain_name');
            $table->text('service_use_case')->nullable()->after('service_description');
        });
    }

    public function down(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn(['service_name', 'domain_name', 'service_description', 'service_use_case']);
        });
    }
};
