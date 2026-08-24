<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->text('user_agent')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['device_id', 'user_id']);
        });

        Schema::create('user_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ip_address']);
            $table->index('ip_address');
        });

        Schema::create('user_risks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('level')->default('low');
            $table->json('breakdown')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->index(['level', 'score']);
        });

        Schema::create('entity_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('strength')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'linked_user_id']);
        });

        Schema::create('fraud_metric_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->integer('weight')->default(0);
            $table->timestamps();
        });

        Schema::create('fraud_level_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('level')->unique();
            $table->string('user_status')->default('active'); // active | review | blocked
            $table->boolean('block_fingerprint')->default(false);
            $table->boolean('block_ip')->default(false);
            $table->boolean('notify_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('fraud_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('level');
            $table->unsignedTinyInteger('score');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('fraud_status')->default('active'); // active | review | blocked
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('fraud_status');
        });
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('fraud_level_policies');
        Schema::dropIfExists('fraud_metric_settings');
        Schema::dropIfExists('entity_links');
        Schema::dropIfExists('user_risks');
        Schema::dropIfExists('user_ips');
        Schema::dropIfExists('device_user');
        Schema::dropIfExists('devices');
    }
};
