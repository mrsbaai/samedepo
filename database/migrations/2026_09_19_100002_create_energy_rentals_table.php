<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('energy_rentals', function (Blueprint $table) {
            $table->id();
            $table->string('network');
            $table->string('receiver_address');
            $table->unsignedInteger('receiver_index');
            $table->string('purpose'); // sweep|withdrawal|payout
            $table->nullableMorphs('purposable');
            $table->unsignedInteger('energy');
            $table->unsignedInteger('duration_sec');
            $table->string('order_id')->nullable()->unique();
            $table->unsignedInteger('unit_price_sun')->nullable();
            $table->decimal('cost_native', 20, 8)->nullable();
            $table->string('status')->default('ordered'); // ordered|filled|failed|expired
            $table->text('error_message')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fee_recovered_at')->nullable();
            $table->timestamps();

            $table->index(['network', 'receiver_address', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_rentals');
    }
};
