<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->string('agent_role')->nullable()->after('agent_name');
        });
    }

    public function down(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn('agent_role');
        });
    }
};
