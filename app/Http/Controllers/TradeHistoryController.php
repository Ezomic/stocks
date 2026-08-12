<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class TradeHistoryController extends Controller
{
    public function index(): View
    {
        $trades = Order::with('position')
            ->where('status', 'filled')
            ->where('side', 'sell')
            ->whereNotNull('fill_price')
            ->whereNotNull('cost_basis')
            ->latest('filled_at')
            ->get();

        return view('trades.index', [
            'trades' => $trades,
            'totals' => $this->totalsByCurrency($trades),
        ]);
    }

    /**
     * Kept per currency for the same reason the portfolio totals are: adding euros to dollars
     * produces a number that is confidently wrong.
     *
     * @param  Collection<int, Order>  $trades
     * @return array<int, array<string, mixed>>
     */
    private function totalsByCurrency(Collection $trades): array
    {
        return $trades
            ->groupBy(fn (Order $order): string => $order->currency ?? '')
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'realised' => $group->sum(fn (Order $order): float => $order->realisedProfit() ?? 0.0),
                'trades' => $group->count(),
            ])
            ->sortBy('currency')
            ->values()
            ->all();
    }
}
