<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Actions\SyncPricesAction;
use App\Models\Setting;
use App\Models\User;
use App\Services\IbkrAuthService;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsController extends Controller
{
    public function index(Request $request, TwoFactorAuthenticator $twoFactor): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('settings.index', [
            'twoFactorConfirmed' => $user->hasTwoFactorEnabled(),
            'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'twoFactorSecret' => $user->two_factor_secret,
            'twoFactorQr' => $user->two_factor_secret === null ? '' : $twoFactor->qrCodeSvg($user),
            'twoFactorRecoveryCodes' => $user->two_factor_recovery_codes ?? [],
            'mode' => config('ibkr.mode'),
            'paperGatewayUrl' => config('ibkr.paper.gateway_url'),
            'liveGatewayUrl' => config('ibkr.live.gateway_url'),
            'paperAccountId' => config('ibkr.paper.account_id'),
            'liveAccountId' => config('ibkr.live.account_id'),
            'tradingEnabled' => Setting::tradingEnabled(),
            'dryRun' => Setting::dryRun(),
            'tokens' => $user->tokens()
                ->latest()
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->getKey(),
                    'name' => $token->name,
                    'createdAtDiff' => $token->created_at?->diffForHumans(),
                    'lastUsedAtDiff' => $token->last_used_at?->diffForHumans(),
                ])
                ->all(),
        ]);
    }

    public function updateTrading(Request $request): RedirectResponse
    {
        $enabled = $request->boolean('trading_enabled');

        Setting::setBool(Setting::TRADING_ENABLED, $enabled);

        return back()->with('success', $enabled
            ? 'Automated trading resumed.'
            : 'Automated trading paused. No orders will be placed.');
    }

    public function updateDryRun(Request $request): RedirectResponse
    {
        $dryRun = $request->boolean('dry_run');

        Setting::setBool(Setting::DRY_RUN, $dryRun);

        return back()->with('success', $dryRun
            ? 'Dry run enabled. Rules will record what they would have done without sending anything to IBKR.'
            : 'Dry run disabled. Triggered rules will place real orders again.');
    }

    public function syncPrices(SyncPricesAction $action): RedirectResponse
    {
        $action->handle();

        return back()->with('success', 'Prices synced.');
    }

    public function evaluateRules(EvaluateRulesAction $action): RedirectResponse
    {
        $action->handle();

        return back()->with('success', 'Rules evaluated.');
    }

    public function syncOrders(SyncOrderStatusAction $action): RedirectResponse
    {
        $action->handle();

        return back()->with('success', 'Orders synced.');
    }

    public function reauth(IbkrAuthService $auth): RedirectResponse
    {
        $success = $auth->reauthenticate();

        return redirect('/')->with($success ? 'success' : 'error', $success ? 'Re-authenticated.' : 'Re-authentication failed.');
    }
}
