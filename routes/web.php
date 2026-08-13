<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\RuleReplayController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TradeHistoryController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/positions/export', [ExportController::class, 'positions'])->name('positions.export');
    Route::resource('positions', PositionController::class)->except(['show'])->names('positions');
    Route::get('/positions/{position}', [PositionController::class, 'show'])->name('positions.show');

    Route::resource('rules', RuleController::class)->except(['show'])->names('rules');
    Route::get('/rules-replay', RuleReplayController::class)->name('rules.replay');

    Route::get('/watchlist', [WatchlistController::class, 'index'])->name('watchlist.index');
    Route::post('/watchlist', [WatchlistController::class, 'store'])->name('watchlist.store');
    Route::delete('/watchlist/{watchlistItem}', [WatchlistController::class, 'destroy'])->name('watchlist.destroy');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [ExportController::class, 'orders'])->name('orders.export');
    Route::get('/trades', [TradeHistoryController::class, 'index'])->name('trades.index');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/trading', [SettingsController::class, 'updateTrading'])->name('settings.trading');
    Route::post('/settings/dry-run', [SettingsController::class, 'updateDryRun'])->name('settings.dry-run');
    Route::post('/settings/dry-run/clear', [SettingsController::class, 'clearDryRun'])->name('settings.dry-run.clear');
    Route::post('/settings/sync-prices', [SettingsController::class, 'syncPrices'])->name('settings.sync-prices');
    Route::post('/settings/evaluate-rules', [SettingsController::class, 'evaluateRules'])->name('settings.evaluate-rules');
    Route::post('/settings/sync-orders', [SettingsController::class, 'syncOrders'])->name('settings.sync-orders');
    Route::post('/settings/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
    Route::post('/settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::post('/settings/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
    Route::post('/settings/two-factor/show-recovery-codes', [TwoFactorController::class, 'showRecoveryCodes'])->name('two-factor.show-recovery-codes');
    Route::delete('/settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    Route::post('/settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('/settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::post('/ibkr/reauth', [SettingsController::class, 'reauth'])->name('ibkr.reauth');
});
