<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_identities', function (Blueprint $table): void {
            $table->id();
            $table->string('role')->unique();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();
        });

        $setting = DB::table('support_settings')->first();

        if ($setting) {
            DB::table('support_identities')->insert([
                'role' => 'support',
                'name' => $setting->agent_name,
                'avatar' => $setting->agent_avatar,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn(['agent_name', 'agent_role', 'agent_avatar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_identities');

        Schema::table('support_settings', function (Blueprint $table): void {
            $table->string('agent_name')->nullable();
            $table->string('agent_role')->nullable();
            $table->string('agent_avatar')->nullable();
        });
    }
};
