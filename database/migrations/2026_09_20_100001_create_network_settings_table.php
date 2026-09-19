<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = ['bitcoin', 'usdt_trc20', 'usdt_erc20'];

    private const NEW_DEFAULTS = [
        'litecoin' => ['min_deposit' => '0.05000000', 'sweep_min_usd' => '10.00'],
        'ethereum' => ['min_deposit' => '0.00500000', 'sweep_min_usd' => '50.00'],
        'usdc_erc20' => ['min_deposit' => '10.00000000', 'sweep_min_usd' => '300.00'],
        'usdt_bep20' => ['min_deposit' => '10.00000000', 'sweep_min_usd' => '10.00'],
        'usdc_bep20' => ['min_deposit' => '10.00000000', 'sweep_min_usd' => '10.00'],
    ];

    public function up(): void
    {
        Schema::create('network_settings', function (Blueprint $table) {
            $table->id();
            $table->string('network', 32)->unique();
            $table->decimal('min_deposit', 20, 8)->default(0);
            $table->decimal('withdrawal_min_usd', 10, 2)->default(0);
            $table->decimal('sweep_min_usd', 10, 2)->default(0);
            $table->string('profit_address', 128)->nullable();
            $table->timestamps();
        });

        $settings = DB::table('platform_settings')->first();

        if ($settings !== null) {
            $rows = [];

            foreach (self::LEGACY as $network) {
                $rows[] = [
                    'network' => $network,
                    'min_deposit' => $settings->{'min_deposit_'.$network} ?? 0,
                    'withdrawal_min_usd' => $settings->{'withdrawal_min_usd_'.$network} ?? 0,
                    'sweep_min_usd' => $settings->{'sweep_min_usd_'.$network} ?? 0,
                    'profit_address' => $settings->{'profit_address_'.$network} ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $withdrawalDefaults = [
                'usdc_erc20' => $settings->withdrawal_min_usd_usdt_erc20 ?? 0,
                'default' => $settings->withdrawal_min_usd_bitcoin ?? 0,
            ];

            foreach (self::NEW_DEFAULTS as $network => $overrides) {
                $rows[] = [
                    'network' => $network,
                    'min_deposit' => $overrides['min_deposit'],
                    'withdrawal_min_usd' => $network === 'usdc_erc20'
                        ? $withdrawalDefaults['usdc_erc20']
                        : $withdrawalDefaults['default'],
                    'sweep_min_usd' => $overrides['sweep_min_usd'],
                    'profit_address' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('network_settings')->insert($rows);
        }

        Schema::table('platform_settings', function (Blueprint $table): void {
            foreach (self::LEGACY as $network) {
                $table->dropColumn([
                    'min_deposit_'.$network,
                    'withdrawal_min_usd_'.$network,
                    'sweep_min_usd_'.$network,
                    'profit_address_'.$network,
                    'confirmations_'.$network,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            foreach (self::LEGACY as $network) {
                $table->decimal('min_deposit_'.$network, 20, 8)->default(0);
                $table->decimal('withdrawal_min_usd_'.$network, 10, 2)->default(0);
                $table->decimal('sweep_min_usd_'.$network, 10, 2)->default(0);
                $table->string('profit_address_'.$network, 128)->nullable();
                $table->integer('confirmations_'.$network)->default(0);
            }
        });

        $rows = DB::table('network_settings')->whereIn('network', self::LEGACY)->get()->keyBy('network');
        $update = [];

        foreach (self::LEGACY as $network) {
            $row = $rows[$network] ?? null;

            if ($row !== null) {
                $update['min_deposit_'.$network] = $row->min_deposit;
                $update['withdrawal_min_usd_'.$network] = $row->withdrawal_min_usd;
                $update['sweep_min_usd_'.$network] = $row->sweep_min_usd;
                $update['profit_address_'.$network] = $row->profit_address;
            }
        }

        if ($update !== []) {
            DB::table('platform_settings')->update($update);
        }

        Schema::dropIfExists('network_settings');
    }
};
