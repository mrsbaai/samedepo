<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_topups', function (Blueprint $table): void {
            $table->string('source_address', 128)->nullable()->after('recipient_index');
            $table->unsignedInteger('source_index')->nullable()->after('source_address');
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->decimal('consolidation_fee', 20, 8)->nullable()->after('network_fee');
        });
    }

    public function down(): void
    {
        Schema::table('gas_topups', function (Blueprint $table): void {
            $table->dropColumn(['source_address', 'source_index']);
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropColumn('consolidation_fee');
        });
    }
};
