<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gas_topups', function (Blueprint $table): void {
            $table->timestamp('fee_recovered_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('gas_topups', function (Blueprint $table): void {
            $table->dropColumn('fee_recovered_at');
        });
    }
};
