<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'deposit_addresses',
        'deposits',
        'withdrawal_addresses',
        'withdrawals',
        'balances',
        'ledger_entries',
        'treasury_sweeps',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('network', 32)->change();
            });
        }

        Schema::table('treasury_wallets', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['network']);
        });
        Schema::table('treasury_wallets', function (Blueprint $blueprint): void {
            $blueprint->string('network', 32)->unique()->change();
        });

        // Gas policies are per chain (one float per native asset); token
        // networks resolve their policy through Network::nativeKey().
        DB::table('gas_policies')->where('network', 'usdt_erc20')->update(['network' => 'native_eth']);
        DB::table('gas_policies')->where('network', 'usdt_trc20')->update(['network' => 'native_trx']);
    }

    public function down(): void
    {
        DB::table('gas_policies')->where('network', 'native_eth')->update(['network' => 'usdt_erc20']);
        DB::table('gas_policies')->where('network', 'native_trx')->update(['network' => 'usdt_trc20']);

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20'])->change();
            });
        }

        Schema::table('treasury_wallets', function (Blueprint $blueprint): void {
            $blueprint->dropUnique(['network']);
        });
        Schema::table('treasury_wallets', function (Blueprint $blueprint): void {
            $blueprint->enum('network', ['bitcoin', 'usdt_trc20', 'usdt_erc20'])->unique()->change();
        });
    }
};
