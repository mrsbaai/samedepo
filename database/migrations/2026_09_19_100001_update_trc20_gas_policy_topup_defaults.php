<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gas_policies')->where('network', 'usdt_trc20')->update([
            'top_up_amount' => '1.00000000',
            'max_top_up' => '20.00000000',
        ]);
    }

    public function down(): void {}
};
