<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gas_policies')->where('network', 'usdt_erc20')->update([
            'reserve_threshold' => '0.00500000',
            'top_up_amount' => '0.00030000',
            'max_top_up' => '0.00100000',
        ]);
        DB::table('gas_policies')->where('network', 'usdt_trc20')->update([
            'reserve_threshold' => '10.00000000',
            'top_up_amount' => '25.00000000',
            'max_top_up' => '50.00000000',
        ]);
    }

    public function down(): void {}
};
