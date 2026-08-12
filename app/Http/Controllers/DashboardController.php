<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Services\IbkrAuthService;
use App\Services\MarketHours;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly IbkrAuthService $auth,
        private readonly MarketHours $marketHours,
    ) {}

    /**
     * Totals are kept per currency rather than added together. Converting would need an FX
     * rate source, which is a second price feed with its own staleness problem, and until
     * there is one a single number across currencies is simply wrong.
     *
     * @param  Collection<int, Position>  $positions
     * @return array<int, array<string, mixed>>
     */
    private function totalsByCurrency(Collection $positions): array
    {
        return $positions
            ->groupBy('currency')
            ->map(function (Collection $group, string $currency): array {
                $value = $group->sum(fn (Position $p): float => is_numeric($p->current_value) ? (float) $p->current_value : 0.0);
                $cost = $group->sum(fn (Position $p): float => (float) $p->avg_buy_price * (float) $p->quantity);

                return [
                    'currency' => $currency,
                    'value' => $value,
                    'gainPct' => $cost > 0.0 ? ($value - $cost) / $cost * 100 : null,
                    'positions' => $group->count(),
                ];
            })
            ->sortBy('currency')
            ->values()
            ->all();
    }

    public function index(): View
    {
        $marketHours = $this->marketHours;

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

        return view('dashboard.index', [
            'positions' => $positions,
            'totals' => $this->totalsByCurrency($positions),
            'ibkrAuthenticated' => $this->auth->isAuthenticated(),
            'recentOrders' => Order::with('position')->latest()->limit(5)->get(),
            'inactiveAccountCount' => Position::count() - count($tradedIds),
            'stalePriceCount' => $stalePriceCount,
            'unreconciledCount' => Order::where('status', 'unreconciled')->count(),
            'driftedPositions' => $positions->filter(fn (Position $p): bool => $p->hasDrift())->values(),
            'closedMarketCount' => $positions->filter(
                fn (Position $p): bool => in_array($p->id, $tradedIds, true) && $marketHours->isOpen($p) === false
            )->count(),
            'globalRule' => Rule::whereNull('position_id')->first(),
            'tradingEnabled' => Setting::tradingEnabled(),
            'dryRun' => Setting::dryRun(),
            'maxPriceAgeMinutes' => PriceSnapshot::maxAgeMinutes(),
            'activeMode' => Position::activeMode(),
            'activeAccountId' => Position::activeAccountId(),
        ]);
    }
}
