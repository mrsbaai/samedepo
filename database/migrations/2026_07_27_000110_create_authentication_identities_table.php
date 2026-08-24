<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_user_hash', 64);
            $table->text('provider_user_id');
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_hash']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_identities');
    }
};
