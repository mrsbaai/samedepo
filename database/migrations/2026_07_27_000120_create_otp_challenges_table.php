<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('purpose');
            $table->string('code');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['email', 'purpose', 'expires_at']);
            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
