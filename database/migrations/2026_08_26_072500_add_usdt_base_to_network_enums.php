<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $old = "('bitcoin', 'usdt_trc20', 'usdt_erc20')";
        $new = "('bitcoin', 'usdt_trc20', 'usdt_erc20', 'usdt_base')";

        DB::statement('PRAGMA writable_schema = ON');
        DB::statement("UPDATE sqlite_master SET sql = replace(sql, ?, ?) WHERE type = 'table'", [$old, $new]);
        $ver = DB::select('PRAGMA schema_version')[0]->schema_version;
        DB::statement('PRAGMA schema_version = '.($ver + 1));
        DB::statement('PRAGMA writable_schema = OFF');
    }
};
