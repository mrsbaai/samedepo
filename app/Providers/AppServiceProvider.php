<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Authentication\ResolvePostSigninRedirect;
use App\Fraud\Contracts\IpIntelProvider;
use App\Fraud\Contracts\NullIpIntelProvider;
use App\Fraud\Contracts\NullPaymentSignalProvider;
use App\Fraud\Contracts\PaymentSignalProvider;
use App\Models\User;
use App\Services\Blockchain\Broadcasters\BlockchainBroadcaster;
use App\Services\Blockchain\Broadcasters\NullBlockchainBroadcaster;
use App\Services\Blockchain\DepositScanner;
use App\Services\Blockchain\PriceFeed\CoinGeckoProvider;
use App\Services\Blockchain\PriceFeed\PriceFeedProvider;
use App\Services\Blockchain\Providers\BlockCypherProvider;
use App\Services\Blockchain\Providers\Contracts\BlockchainProvider;
use App\Services\Blockchain\Providers\InfuraProvider;
use App\Services\Blockchain\Providers\NullBlockchainProvider;
use App\Services\Blockchain\Providers\TronGridProvider;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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

        $this->app->bind(BlockchainBroadcaster::class, NullBlockchainBroadcaster::class);
        $this->app->bind(PriceFeedProvider::class, CoinGeckoProvider::class);

        $this->app->singleton(DepositScanner::class, function () {
            $networks = ['bitcoin', 'usdt_trc20', 'usdt_erc20'];
            $providers = [];

            foreach ($networks as $network) {
                $providers[] = $this->makeBlockchainProvider($network);
            }

            return new DepositScanner($providers);
        });
    }

    private function makeBlockchainProvider(string $network): BlockchainProvider
    {
        $config = config("blockchain.providers.{$network}");
        $driver = $config['driver'] ?? null;

        return match ($driver) {
            'blockcypher' => new BlockCypherProvider(
                network: $network,
                coinSymbol: 'btc',
                token: $config['token'] ?? null,
                apiNetwork: $config['network'] ?? 'main',
            ),
            'trongrid' => new TronGridProvider(
                network: $network,
                usdtContract: $config['usdt_contract'] ?? 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                apiKey: $config['api_key'] ?? null,
            ),
            'infura' => new InfuraProvider(
                network: $network,
                usdtContract: $config['usdt_contract'] ?? '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                projectId: $config['project_id'] ?? null,
                projectSecret: $config['project_secret'] ?? null,
                infuraNetwork: $config['network'] ?? 'mainnet',
            ),
            default => new NullBlockchainProvider($network),
        };
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureRememberDuration();
        $this->configureAuthenticatedGuestRedirect();
        $this->configureDefaultAppearance();
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
    }

    private function configureDefaultAppearance(): void
    {
        Blade::directive('fluxAppearance', function ($expression): string {
            return "<?php
                \$default = addslashes(config('app.appearance', 'dark'));
                echo str_replace(
                    \"window.Flux.applyAppearance(window.localStorage.getItem('flux.appearance') || 'system')\",
                    \"window.Flux.applyAppearance(window.localStorage.getItem('flux.appearance') || '\" . \$default . \"')\",
                    app('flux')->fluxAppearance({$expression})
                );
            ?>";
        });
    }
}
