<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Services\IbkrAuthService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly IbkrAuthService $auth) {}

    public function index(): View
    {
        $positions = Position::with(['rule', 'orders' => fn (Relation $q) => $q->latest()->limit(1)])->get();

        $positions = $positions->map(function (Position $position) {
            $snapshot = PriceSnapshot::latestFor($position->symbol);
            $position->current_price = $snapshot ? (float) $snapshot->price : null;
            $position->gain_pct = $snapshot ? $position->gainPct((float) $snapshot->price) : null;
            $position->current_value = $snapshot ? (float) $snapshot->price * (float) $position->quantity : null;

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
            'inactiveAccountCount' => Position::count() - Position::forActiveAccount()->count(),
            'activeMode' => Position::activeMode(),
            'activeAccountId' => Position::activeAccountId(),
        ]);
    }
}
