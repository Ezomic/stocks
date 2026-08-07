<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Actions\SyncPricesAction;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\IbkrAuthService;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
            'twoFactorRecoveryCodes' => $this->visibleRecoveryCodes($user),
            'mode' => config('ibkr.mode'),
            'paperGatewayUrl' => config('ibkr.paper.gateway_url'),
            'liveGatewayUrl' => config('ibkr.live.gateway_url'),
            'paperAccountId' => config('ibkr.paper.account_id'),
            'liveAccountId' => config('ibkr.live.account_id'),
            'tradingEnabled' => Setting::tradingEnabled(),
            'dryRun' => Setting::dryRun(),
            'simulatedOrderCount' => Order::where('status', 'simulated')->count(),
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

    /**
     * Recovery codes are second factors in their own right, so once two-factor is live they
     * are only shown when they have just been generated or deliberately revealed. Before it is
     * live the secret protects nothing yet, and losing the codes mid-setup is the worse
     * outcome, so they stay on screen until enrolment is confirmed.
     *
     * @return array<int, string>
     */
    private function visibleRecoveryCodes(User $user): array
    {
        $flashed = session('recoveryCodes');

        if (is_array($flashed)) {
            return array_values(array_filter($flashed, 'is_string'));
        }

        return $user->hasTwoFactorEnabled() ? [] : ($user->two_factor_recovery_codes ?? []);
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

    /**
     * Only ever touches simulated records, so real order history can never be caught up in it.
     */
    public function clearDryRun(): RedirectResponse
    {
        $cleared = Order::where('status', 'simulated')->count();

        if ($cleared > 0) {
            Order::where('status', 'simulated')->delete();
        }

        return back()->with('success', $cleared === 0
            ? 'There were no simulated orders to clear.'
            : "Cleared {$cleared} simulated ".Str::plural('order', $cleared).'. Positions can trigger again in the simulation.');
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
