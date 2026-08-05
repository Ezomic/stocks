<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Setting;
use App\Services\IbkrAuthService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly IbkrAuthService $auth) {}

    public function index(): View
    {
        $positions = Position::with([
            'latestSnapshot',
            'rule',
            'orders' => fn (Relation $q) => $q->latest()->limit(1),
        ])->get();

        $tradedIds = Position::forActiveAccount()->pluck('id')->all();
        $stalePriceCount = 0;

        $positions = $positions->map(function (Position $position) use ($tradedIds, &$stalePriceCount) {
            $snapshot = $position->latestSnapshot;
            $position->current_price = $snapshot ? (float) $snapshot->price : null;
            $position->gain_pct = $snapshot ? $position->gainPct((float) $snapshot->price) : null;
            $position->current_value = $snapshot ? (float) $snapshot->price * (float) $position->quantity : null;
            $position->price_is_stale = $snapshot === null || $snapshot->isStale();

            if ($position->price_is_stale && in_array($position->id, $tradedIds, true)) {
                $stalePriceCount++;
            }

            return $position;
        });

        $totalValue = $positions->sum(fn (Position $p): float => is_numeric($p->current_value) ? (float) $p->current_value : 0.0);
        $totalCost = $positions->sum(fn ($p) => (float) $p->avg_buy_price * (float) $p->quantity);
        $totalGainPct = $totalCost > 0 ? ($totalValue - $totalCost) / $totalCost * 100 : 0;

        return view('dashboard.index', [
            'positions' => $positions,
            'totalValue' => $totalValue,
            'totalGainPct' => $totalGainPct,
            'ibkrAuthenticated' => $this->auth->isAuthenticated(),
            'recentOrders' => Order::with('position')->latest()->limit(5)->get(),
            'inactiveAccountCount' => Position::count() - count($tradedIds),
            'stalePriceCount' => $stalePriceCount,
            'tradingEnabled' => Setting::tradingEnabled(),
            'dryRun' => Setting::dryRun(),
            'maxPriceAgeMinutes' => PriceSnapshot::maxAgeMinutes(),
            'activeMode' => Position::activeMode(),
            'activeAccountId' => Position::activeAccountId(),
        ]);
    }
}
