<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->after('email');
            $table->enum('role', ['owner', 'admin'])->default('owner')->after('password');
            $table->enum('withdrawal_mode', ['instant', 'approval'])->nullable()->after('role');
            $table->decimal('deposit_fee_override', 5, 2)->nullable()->after('withdrawal_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['name', 'role', 'withdrawal_mode', 'deposit_fee_override']);
        });
    }
};
