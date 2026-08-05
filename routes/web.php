<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('positions', PositionController::class)->except(['show'])->names('positions');
    Route::get('/positions/{position}', [PositionController::class, 'show'])->name('positions.show');

    Route::resource('rules', RuleController::class)->except(['show'])->names('rules');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/trading', [SettingsController::class, 'updateTrading'])->name('settings.trading');
    Route::post('/settings/dry-run', [SettingsController::class, 'updateDryRun'])->name('settings.dry-run');
    Route::post('/settings/sync-prices', [SettingsController::class, 'syncPrices'])->name('settings.sync-prices');
    Route::post('/settings/evaluate-rules', [SettingsController::class, 'evaluateRules'])->name('settings.evaluate-rules');
    Route::post('/settings/sync-orders', [SettingsController::class, 'syncOrders'])->name('settings.sync-orders');
    Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::post('/ibkr/reauth', [SettingsController::class, 'reauth'])->name('ibkr.reauth');
});
