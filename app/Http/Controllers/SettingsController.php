<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Actions\SyncPricesAction;
use App\Models\User;
use App\Services\IbkrAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return view('settings.index', [
            'mode' => config('ibkr.mode'),
            'paperGatewayUrl' => config('ibkr.paper.gateway_url'),
            'liveGatewayUrl' => config('ibkr.live.gateway_url'),
            'paperAccountId' => config('ibkr.paper.account_id'),
            'liveAccountId' => config('ibkr.live.account_id'),
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
