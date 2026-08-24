<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('customer_reference');
            $table->timestamps();

            $table->unique(['user_id', 'customer_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
