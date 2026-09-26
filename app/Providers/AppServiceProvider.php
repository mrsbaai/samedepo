<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Fraud\Contracts\IpIntelProvider;
use App\Fraud\Contracts\NullIpIntelProvider;
use App\Fraud\Contracts\NullPaymentSignalProvider;
use App\Fraud\Contracts\PaymentSignalProvider;
use App\Models\ApiKey;
use App\Models\PlatformSettings;
use App\Models\User;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\NullBlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\RemoteBlockchainBroadcaster;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\PriceFeed\CoinGeckoProvider;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;
use App\Services\Blockchain\Providers\BlockCypherProvider;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\Providers\EsploraProvider;
use App\Services\Blockchain\Providers\EtherscanNativeProvider;
use App\Services\Blockchain\Providers\EvmLogsProvider;
use App\Services\Blockchain\Providers\FallbackBlockchainProvider;
use App\Services\Blockchain\Providers\NodeRealNativeProvider;
use App\Services\Blockchain\Providers\NullBlockchainProvider;
use App\Services\Blockchain\Providers\TronGridProvider;
use App\Services\Blockchain\Providers\TronscanProvider;
use App\Support\Network;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fraud Engine extension points; replaced by real implementations
        // when a billing module or IP intelligence service is added.
        $this->app->bind(PaymentSignalProvider::class, NullPaymentSignalProvider::class);
        $this->app->bind(IpIntelProvider::class, NullIpIntelProvider::class);

        if (config('blockchain.signer.url') && config('blockchain.signer.api_key')) {
            $this->app->bind(BlockchainBroadcaster::class, function () {
                return new RemoteBlockchainBroadcaster(
                    config('blockchain.signer.url'),
                    config('blockchain.signer.api_key'),
                );
            });
        } else {
            $this->app->bind(BlockchainBroadcaster::class, NullBlockchainBroadcaster::class);
        }
        $this->app->bind(PriceFeedProvider::class, CoinGeckoProvider::class);

        $this->app->singleton(DepositScanner::class, function () {
            $providers = [];

            foreach (Network::enabledKeys() as $network) {
                $providers[] = $this->makeBlockchainProvider($network);
            }

            return new DepositScanner($providers);
        });
    }

    private function makeBlockchainProvider(string $network): BlockchainProvider
    {
        $provider = $this->makeProviderFromOptions($network, Network::provider($network));

        $fallback = Network::fallbackProvider($network);
        $fallbackUrl = $fallback['base_url'] ?? $fallback['rpc'] ?? null;

        if (isset($fallback['driver']) && is_string($fallbackUrl) && $fallbackUrl !== '') {
            return new FallbackBlockchainProvider(
                $provider,
                $this->makeProviderFromOptions($network, $fallback),
            );
        }

        return $provider;
    }

    private function makeProviderFromOptions(string $network, array $options): BlockchainProvider
    {
        $driver = $options['driver'] ?? null;

        return match ($driver) {
            'esplora' => new EsploraProvider(
                network: $network,
                baseUrl: $options['base_url'] ?? 'https://mempool.space/api',
            ),
            'trongrid' => new TronGridProvider(
                network: $network,
                usdtContract: $options['contract'] ?? (string) Network::contract($network),
                apiKey: $options['api_key'] ?? null,
            ),
            'tronscan' => new TronscanProvider(
                network: $network,
                contract: $options['contract'] ?? (string) Network::contract($network),
                baseUrl: $options['base_url'] ?? 'https://apilist.tronscanapi.com',
                apiKey: $options['api_key'] ?? null,
            ),
            'blockcypher' => new BlockCypherProvider(
                network: $network,
                baseUrl: (string) ($options['base_url'] ?? 'https://api.blockcypher.com/v1/ltc/main'),
                token: $options['token'] ?? null,
            ),
            'evm_logs' => new EvmLogsProvider(
                network: $network,
                contract: (string) Network::contract($network),
                rpcUrl: $options['rpc'] ?? null,
                projectId: $options['project_id'] ?? null,
                projectSecret: $options['project_secret'] ?? null,
                infuraNetwork: $options['infura_network'] ?? 'mainnet',
                tokenDecimals: Network::tokenDecimals($network) ?? 6,
                blockRange: (int) ($options['max_block_range'] ?? 10000),
            ),
            'etherscan_native' => new EtherscanNativeProvider(
                network: $network,
                apiKey: (string) ($options['api_key'] ?? ''),
                chainId: (int) ($options['chain_id'] ?? 1),
                baseUrl: $options['base_url'] ?? 'https://api.etherscan.io/v2/api',
                requiresApiKey: (bool) ($options['requires_api_key'] ?? true),
            ),
            'nodereal_native' => new NodeRealNativeProvider(
                network: $network,
                rpcUrl: $options['rpc'] ?? null,
            ),
            default => new NullBlockchainProvider($network),
        };
    }

    public function boot(): void
    {
        Network::applyOverrides();
        $this->configureRateLimiting();
        $this->configureRememberDuration();
        $this->configureAuthenticatedGuestRedirect();
    }

    private function configureAuthenticatedGuestRedirect(): void
    {
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            /** @var User $user */
            $user = $request->user();

            return ResolvePostSigninRedirect::for($user);
        });
    }

    private function configureRememberDuration(): void
    {
        $guard = Auth::guard();

        if ($guard instanceof SessionGuard) {
            $days = (int) config('authentication.remember.days', 30);
            $guard->setRememberDuration($days * 24 * 60);
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('signin', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute((int) config('authentication.rate_limits.signin'))
                ->by('signin|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('signup', function (Request $request): Limit {
            return Limit::perMinute((int) config('authentication.rate_limits.signup'))
                ->by('signup|'.$request->ip());
        });

        RateLimiter::for('password-recovery', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute((int) config('authentication.rate_limits.password_recovery'))
                ->by('password-recovery|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('otp-verification', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute((int) config('authentication.rate_limits.otp_verification'))
                ->by('otp-verification|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('otp-resend', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute((int) config('authentication.rate_limits.otp_resend'))
                ->by('otp-resend|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('verification-resend', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute((int) config('authentication.rate_limits.verification_resend'))
                ->by('verification-resend|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $id = (string) $request->session()->get('signin.id', 'guest');

            return Limit::perMinute((int) config('authentication.rate_limits.two_factor'))
                ->by('two-factor|'.$id.'|'.$request->ip());
        });

        RateLimiter::for('api-key', function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            if (! $apiKey instanceof ApiKey) {
                return Limit::none();
            }

            $key = 'api-key|'.$apiKey->getKey();

            return Limit::perMinute(PlatformSettings::instance()->api_requests_per_minute)
                ->by($key)
                ->response(function () use ($key): JsonResponse {
                    $limit = PlatformSettings::instance()->api_requests_per_minute;

                    return response()->json([
                        'message' => 'API rate limit exceeded. Please retry after the time indicated by the Retry-After header.',
                    ], 429, [
                        'X-RateLimit-Limit' => (string) $limit,
                        'X-RateLimit-Remaining' => '0',
                        'Retry-After' => (string) RateLimiter::availableIn($key),
                    ]);
                });
        });
    }
}
