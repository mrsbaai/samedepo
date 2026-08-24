<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Website Owner Dashboard Navigation
    |--------------------------------------------------------------------------
    |
    | The seven routes tagged "Main navigation" as an entry point in
    | .project/00-input/ui-spec/routes.md for GROUP-OWNER-DASHBOARD.
    | Customer Detail and Withdraw are reached from other views (a customer
    | row, a balance card) and are intentionally not top-level nav items.
    | Each item's "route" name may not exist yet as later dashboard views
    | ship (TASK-003 onward); the layout falls back to the raw "path" via
    | url() until the named route is registered.
    |
    */

    'owner' => [
        'main' => [
            [
                'icon' => 'home',
                'route' => 'dashboard',
                'path' => '/dashboard',
                'label' => 'Dashboard Home',
            ],
            [
                'icon' => 'users',
                'route' => 'customers',
                'path' => '/customers',
                'label' => 'Customers',
            ],
            [
                'icon' => 'arrow-down-tray',
                'route' => 'deposits',
                'path' => '/deposits',
                'label' => 'Deposits',
            ],
            [
                'icon' => 'clock',
                'route' => 'transactions',
                'path' => '/transactions',
                'label' => 'Transaction History',
            ],
            [
                'icon' => 'key',
                'route' => 'api-keys',
                'path' => '/api-keys',
                'label' => 'API Keys',
            ],
            [
                'icon' => 'globe-alt',
                'route' => 'webhook-settings',
                'path' => '/webhook-settings',
                'label' => 'Webhook Settings',
            ],
            [
                'icon' => 'banknotes',
                'route' => 'withdrawal-settings',
                'path' => '/withdrawal-settings',
                'label' => 'Withdrawal Settings',
            ],
            [
                'icon' => 'lifebuoy',
                'route' => 'support',
                'path' => '/support',
                'label' => 'Support',
            ],
        ],
        'settings' => [],
    ],

];
