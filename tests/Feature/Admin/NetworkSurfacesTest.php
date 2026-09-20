<?php

use App\Livewire\Admin\PlatformSettings;
use App\Livewire\Dashboard\CustomerDetail;
use App\Livewire\Dashboard\WithdrawalSettings;
use App\Models\BlockchainScanState;
use App\Models\Customer;
use App\Models\DepositAddress;
use App\Models\NetworkSetting;
use App\Models\PlatformSettings as PlatformSettingsModel;
use App\Models\TreasuryWallet;
use App\Models\User;
use App\Support\Network;
use Livewire\Livewire;

test('network settings enabled flag overrides the registry env flag', function () {
    expect(Network::enabledKeys())->not->toContain('litecoin');

    NetworkSetting::create([
        'network' => 'litecoin',
        'min_deposit' => '0.001',
        'withdrawal_min_usd' => '100',
        'sweep_min_usd' => '50',
        'enabled' => true,
    ]);
    Network::flush();

    expect(Network::enabledKeys())->toContain('litecoin');

    PlatformSettingsModel::networkSetting('bitcoin')->update(['enabled' => false]);
    Network::flush();

    expect(Network::enabledKeys())->not->toContain('bitcoin');
});

test('enabling a network from platform settings persists the flag and provisions rows', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();
    TreasuryWallet::factory()->create(['network' => 'usdt_erc20', 'derivation_index' => 0, 'address' => '0xshared']);

    expect(Network::enabledKeys())->not->toContain('usdc_erc20');

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->call('requestToggle', 'usdc_erc20')
        ->assertSet('toggleTarget', true)
        ->call('confirmToggle')
        ->assertHasNoErrors();

    Network::flush();

    expect(Network::enabledKeys())->toContain('usdc_erc20')
        ->and(NetworkSetting::where('network', 'usdc_erc20')->value('enabled'))->toBeTrue()
        ->and(BlockchainScanState::where('network', 'usdc_erc20')->exists())->toBeTrue()
        ->and(TreasuryWallet::where('network', 'usdc_erc20')->value('address'))->toBe('0xshared');
});

test('disabling a network persists the flag without deleting rows', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();
    $wallet = TreasuryWallet::factory()->create(['network' => 'bitcoin']);

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->call('requestToggle', 'bitcoin')
        ->assertSet('toggleTarget', false)
        ->call('confirmToggle')
        ->assertHasNoErrors();

    Network::flush();

    expect(Network::enabledKeys())->not->toContain('bitcoin')
        ->and(NetworkSetting::where('network', 'bitcoin')->value('enabled'))->toBeFalse()
        ->and(TreasuryWallet::where('network', 'bitcoin')->exists())->toBeTrue();
});

test('the settings table lists every registry network including disabled ones', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    $this->actingAs($admin)
        ->get(route('admin.platform-settings'))
        ->assertOk()
        ->assertSee('USDT (TRC20)', false)
        ->assertSee('Litecoin', false)
        ->assertSee('USDC (BEP20)', false)
        ->assertSee('Ethereum', false);
});

test('saving a network row persists its limits and profit address', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('rows.litecoin.min_deposit', '0.002')
        ->set('rows.litecoin.withdrawal_min_usd', '75')
        ->set('rows.litecoin.sweep_min_usd', '40')
        ->set('rows.litecoin.profit_address', 'ltc1qlnjr9jmzv84jfyuvq8v6d4vshf5mqcukuqv0sa')
        ->call('saveNetworkRow', 'litecoin')
        ->assertHasNoErrors();

    $setting = NetworkSetting::where('network', 'litecoin')->first();
    expect($setting->min_deposit)->toBe('0.00200000')
        ->and($setting->withdrawal_min_usd)->toBe('75.00')
        ->and($setting->sweep_min_usd)->toBe('40.00')
        ->and($setting->profit_address)->toBe('ltc1qlnjr9jmzv84jfyuvq8v6d4vshf5mqcukuqv0sa');
});

test('a network row rejects a profit address from the wrong group with the group hint', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_admin' => true]);
    PlatformSettingsModel::instance();

    Livewire::actingAs($admin)
        ->test(PlatformSettings::class)
        ->set('rows.litecoin.profit_address', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq')
        ->call('saveNetworkRow', 'litecoin')
        ->assertHasErrors(['rows.litecoin.profit_address'])
        ->assertSeeText('Enter a Litecoin address (ltc1… or L…/M…/3…).');
});

test('deposit addresses group into one card per address with every asset listed', function () {
    config()->set('networks.networks.usdc_erc20.enabled', true);
    Network::flush();

    $owner = User::factory()->create(['role' => 'owner']);
    $customer = Customer::factory()->create(['user_id' => $owner->id, 'customer_reference' => 'cus_grouped']);
    $shared = '0x'.str_repeat('a', 40);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdt_erc20', 'address' => $shared]);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'usdc_erc20', 'address' => $shared]);
    DepositAddress::factory()->create(['customer_id' => $customer->id, 'network' => 'bitcoin', 'address' => 'bc1q-grouped']);

    Livewire::actingAs($owner)
        ->test(CustomerDetail::class, ['customer' => 'cus_grouped'])
        ->assertSeeInOrder([
            'USDT (ERC20)',
            'USDC (ERC20)',
        ], false)
        ->assertSee('Ethereum', false);

    $component = Livewire::actingAs($owner)->test(CustomerDetail::class, ['customer' => 'cus_grouped']);
    $addresses = $component->instance()->addresses;
    $groups = collect($addresses)->groupBy('address');

    expect($groups)->toHaveCount(2)
        ->and($groups[$shared])->toHaveCount(2)
        ->and($groups['bc1q-grouped'])->toHaveCount(1);
});

test('disabled networks are hidden from landing, limits, api docs and owner surfaces', function () {
    config()->set('networks.networks.usdc_erc20.enabled', true);
    config()->set('networks.networks.litecoin.enabled', false);
    Network::flush();

    $this->get(route('public.landing'))
        ->assertOk()
        ->assertSee('USDC (ERC20)', false)
        ->assertDontSee('Litecoin', false);

    $this->get(route('public.api-docs', ['tab' => 'limits']))
        ->assertOk()
        ->assertSee('usdc_erc20', false)
        ->assertDontSee('litecoin', false);

    $owner = User::factory()->create(['role' => 'owner']);
    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('USDC (ERC20)', false)
        ->assertDontSee('Litecoin', false);
});

test('withdrawal address validation uses the per-group hint message', function () {
    config()->set('networks.networks.litecoin.enabled', true);
    Network::flush();

    $owner = User::factory()->create(['role' => 'owner']);

    Livewire::actingAs($owner)
        ->test(WithdrawalSettings::class)
        ->call('startEdit', 'litecoin', '')
        ->set('editingAddress', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq')
        ->call('confirmSave')
        ->assertHasErrors(['editingAddress' => 'regex'])
        ->assertSeeText('Enter a Litecoin address (ltc1… or L…/M…/3…).');
});
