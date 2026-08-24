<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('type'); // ip | device
            $table->string('value');
            $table->string('reason')->nullable();
            $table->string('source')->default('manual'); // threat_detector | fraud_engine | manual
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'value']);
        });

        Schema::create('threat_events', function (Blueprint $table): void {
            $table->id();
            $table->string('detector');
            $table->string('threat_type');
            $table->unsignedTinyInteger('severity');
            $table->string('description');
            $table->text('payload')->nullable();
            $table->string('ip_address', 45);
            $table->string('fingerprint')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('path', 512);
            $table->boolean('blocked')->default(false);
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['detector', 'created_at']);
        });

        Schema::create('detector_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detector_settings');
        Schema::dropIfExists('threat_events');
        Schema::dropIfExists('security_blocks');
    }
};
