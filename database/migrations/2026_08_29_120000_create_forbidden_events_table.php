<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forbidden_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->index();
            $table->string('reason')->nullable();
            $table->string('path');
            $table->string('method', 10);
            $table->string('ip_address')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('user_agent')->nullable();
            $table->foreignId('threat_event_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['source', 'created_at']);
            $table->index(['reason', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forbidden_events');
    }
};
