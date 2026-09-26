<?php

use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\Providers\FallbackBlockchainProvider;
use App\Services\Blockchain\ValueObjects\BlockchainTransaction;
use Illuminate\Support\Facades\Log;

class FallbackTestProvider implements BlockchainProvider
{
    /** @var array<int, array<int, string>> */
    public array $calls = [];

    /**
     * @param  array<int, BlockchainTransaction>  $result
     */
    public function __construct(
        private readonly string $network,
        private readonly array $result = [],
        private readonly ?Throwable $throws = null,
    ) {}

    public function fetchTransactions(array $addresses): array
    {
        $this->calls[] = $addresses;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result;
    }

    public function network(): string
    {
        return $this->network;
    }
}

function fallbackTx(string $hash = '0x1'): BlockchainTransaction
{
    return new BlockchainTransaction(
        network: 'bitcoin',
        txHash: $hash,
        toAddress: 'addr',
        amount: '1.00000000',
        confirmations: 3,
    );
}

test('a successful primary result is returned without calling the fallback', function () {
    $primary = new FallbackTestProvider('bitcoin', [fallbackTx()]);
    $fallback = new FallbackTestProvider('bitcoin', [fallbackTx('0x2')]);

    $provider = new FallbackBlockchainProvider($primary, $fallback);

    expect($provider->network())->toBe('bitcoin')
        ->and($provider->fetchTransactions(['a']))->toHaveCount(1)
        ->and($provider->fetchTransactions(['a'])[0]->txHash)->toBe('0x1')
        ->and($fallback->calls)->toBeEmpty();
});

test('a partial primary result never invokes the fallback', function () {
    // The primary legitimately found only some transfers; no exception means
    // the fallback must not run and merge a duplicate view of the chain.
    $primary = new FallbackTestProvider('bitcoin', [fallbackTx()]);
    $fallback = new FallbackTestProvider('bitcoin', [fallbackTx('0x2'), fallbackTx('0x3')]);

    $provider = new FallbackBlockchainProvider($primary, $fallback);
    $result = $provider->fetchTransactions(['a']);

    expect($result)->toHaveCount(1)
        ->and($fallback->calls)->toBeEmpty();
});

test('a primary exception logs one warning and returns the fallback result', function () {
    Log::spy();

    $primary = new FallbackTestProvider('bitcoin', [], new RuntimeException('primary down'));
    $fallback = new FallbackTestProvider('bitcoin', [fallbackTx('0x2')]);

    $provider = new FallbackBlockchainProvider($primary, $fallback);
    $result = $provider->fetchTransactions(['a', 'b']);

    expect($result)->toHaveCount(1)
        ->and($result[0]->txHash)->toBe('0x2')
        ->and($fallback->calls)->toBe([['a', 'b']]);

    Log::shouldHaveReceived('warning')->once()->with(
        'Blockchain provider failed; using fallback.',
        Mockery::on(fn (array $context): bool => $context['network'] === 'bitcoin'
            && $context['primary'] === FallbackTestProvider::class
            && $context['fallback'] === FallbackTestProvider::class
            && $context['error'] === 'primary down'),
    );
});

test('a fallback exception propagates instead of the primary one', function () {
    Log::spy();

    $primary = new FallbackTestProvider('bitcoin', [], new RuntimeException('primary down'));
    $fallback = new FallbackTestProvider('bitcoin', [], new InvalidArgumentException('fallback down'));

    $provider = new FallbackBlockchainProvider($primary, $fallback);

    expect(fn () => $provider->fetchTransactions(['a']))
        ->toThrow(InvalidArgumentException::class, 'fallback down');
});
