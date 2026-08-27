<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gas_policies', function (Blueprint $table) {
            $table->id();
            $table->string('network')->unique();
            $table->decimal('reserve_threshold', 20, 8);
            $table->decimal('top_up_amount', 20, 8);
            $table->decimal('max_top_up', 20, 8);
            $table->boolean('manual_paused')->default(false);
            $table->unsignedInteger('alert_cooldown')->default(60);
            $table->timestamp('last_alert_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gas_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('treasury_wallet_id')->constrained('treasury_wallets')->cascadeOnDelete();
            $table->string('network');
            $table->string('recipient_address');
            $table->unsignedInteger('recipient_index');
            $table->decimal('amount', 20, 8);
            $table->string('tx_hash')->nullable();
            $table->string('status')->default('pending');
            $table->string('is_open')->default('open');
            $table->text('error_message')->nullable();
            $table->timestamp('broadcasted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'recipient_address', 'is_open']);
        });

        Schema::create('gas_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gas_topup_id')->nullable()->constrained('gas_topups')->nullOnDelete();
            $table->string('network');
            $table->string('tx_hash')->nullable();
            $table->decimal('amount', 20, 8)->nullable();
            $table->string('expensable_type')->nullable();
            $table->unsignedBigInteger('expensable_id')->nullable();
            $table->timestamps();
        });

        Schema::table('treasury_wallets', function (Blueprint $table) {
            $table->decimal('native_balance', 20, 8)->nullable()->after('available_funds');
            $table->unsignedBigInteger('energy')->nullable()->after('native_balance');
            $table->unsignedBigInteger('bandwidth')->nullable()->after('energy');
            $table->timestamp('refreshed_at')->nullable()->after('bandwidth');
        });
    }

    public function down(): void
    {
        Schema::table('treasury_wallets', function (Blueprint $table) {
            $table->dropColumn(['native_balance', 'energy', 'bandwidth', 'refreshed_at']);
        });

        Schema::dropIfExists('gas_expenses');
        Schema::dropIfExists('gas_topups');
        Schema::dropIfExists('gas_policies');
    }
};
