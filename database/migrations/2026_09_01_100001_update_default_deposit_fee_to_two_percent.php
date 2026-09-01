<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE platform_settings SET global_deposit_fee_percent = '2.00' WHERE global_deposit_fee_percent = '1.00'");
    }

    public function down(): void
    {
        DB::update("UPDATE platform_settings SET global_deposit_fee_percent = '1.00' WHERE global_deposit_fee_percent = '2.00'");
    }
};
