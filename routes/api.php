<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Middleware\ApiKeyAuthentication;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiKeyAuthentication::class)->prefix('v1')->group(function (): void {
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{reference}', [CustomerController::class, 'show']);
    Route::get('/balances', BalanceController::class);
});
