<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlatformSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use ReflectionException;
use ReflectionMethod;

class ApiDocsController
{
    public function __invoke(): View
    {
        $settings = PlatformSettings::instance();
        $user = auth()->user();
        $depositFee = $user?->role === 'owner' && ! $user->is_admin && $user->deposit_fee_override !== null
            ? $user->deposit_fee_override
            : $settings->global_deposit_fee_percent;

        return view('public.api-docs', [
            'baseUrl' => url('/api/v1'),
            'endpoints' => $this->endpoints(),
            'rateLimit' => $settings->api_requests_per_minute,
            'settings' => $settings,
            'depositFee' => $depositFee,
        ]);
    }

    private function endpoints(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
            ->flatMap(function (Route $route): Collection {
                $group = $this->groupFor($route);
                $description = $this->descriptionFor($route);

                return collect($route->methods())
                    ->reject(fn (string $method) => $method === 'HEAD')
                    ->map(fn (string $method) => [
                        'method' => $method,
                        'uri' => '/'.$route->uri(),
                        'group' => $group,
                        'description' => $description,
                    ]);
            })
            ->sortBy('uri')
            ->groupBy('group')
            ->sortBy(fn ($items, $group) => match ($group) {
                'Customers' => 0,
                'Balances' => 1,
                default => 999,
            });
    }

    private function groupFor(Route $route): string
    {
        $tail = Str::after($route->uri(), 'api/v1/');

        return Str::headline(Str::before($tail, '/'));
    }

    private function descriptionFor(Route $route): string
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return '';
        }

        [$class, $method] = explode('@', $action);

        try {
            $reflection = new ReflectionMethod($class, $method);
            $comment = $reflection->getDocComment();

            if ($comment === false) {
                return '';
            }

            foreach (explode("\n", $comment) as $line) {
                $line = ltrim($line, " \t*/");

                if ($line !== '' && ! str_starts_with($line, '@')) {
                    return $line;
                }
            }
        } catch (ReflectionException) {
            // Silently ignore methods that cannot be reflected.
        }

        return '';
    }
}
