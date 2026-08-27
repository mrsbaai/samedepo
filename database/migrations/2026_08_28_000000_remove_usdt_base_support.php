<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        $networkTables = [
            'gas_expenses',
            'gas_topups',
            'gas_policies',
            'treasury_sweeps',
            'ledger_entries',
            'deposits',
            'deposit_addresses',
            'withdrawals',
            'withdrawal_addresses',
            'balances',
            'usd_valuations',
            'treasury_wallets',
        ];

        $this->disableForeignKeyChecks($driver);

        try {
            foreach ($networkTables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('network', 'usdt_base')->delete();
                }
            }
        } finally {
            $this->enableForeignKeyChecks($driver);
        }

        $this->restoreConstraints($driver);
    }

    public function down(): void {}

    private function disableForeignKeyChecks(string $driver): void
    {
        match ($driver) {
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS = 0'),
            default => null,
        };
    }

    private function enableForeignKeyChecks(string $driver): void
    {
        match ($driver) {
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS = 1'),
            default => null,
        };
    }

    private function restoreConstraints(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->restoreSqliteConstraints();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->restoreMySqlConstraints();
        }
    }

    private function restoreSqliteConstraints(): void
    {
        DB::statement('PRAGMA writable_schema = ON');

        $old = "('bitcoin', 'usdt_trc20', 'usdt_erc20', 'usdt_base')";
        $new = "('bitcoin', 'usdt_trc20', 'usdt_erc20')";

        DB::statement("UPDATE sqlite_master SET sql = replace(sql, ?, ?) WHERE type = 'table'", [$old, $new]);

        $ver = DB::select('PRAGMA schema_version')[0]->schema_version;
        DB::statement('PRAGMA schema_version = '.($ver + 1));

        DB::statement('PRAGMA writable_schema = OFF');
    }

    private function restoreMySqlConstraints(): void
    {
        $tables = [
            'deposit_addresses',
            'deposits',
            'withdrawal_addresses',
            'withdrawals',
            'balances',
            'ledger_entries',
            'treasury_wallets',
            'usd_valuations',
            'treasury_sweeps',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `network` ENUM('bitcoin', 'usdt_trc20', 'usdt_erc20') NOT NULL");
        }
    }
};
