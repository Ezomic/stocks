<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ThresholdCrossed;
use App\Services\IbkrAuthService;
use App\Services\MarketHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class EvaluateRulesAction
{
    public function __construct(
        private readonly PlaceOrderAction $placeOrder,
        private readonly IbkrAuthService $auth,
        private readonly MarketHours $marketHours,
    ) {}

    /**
     * An alert rule shares the thresholds and the cooldown with a trading rule, so the two
     * cannot disagree about when a level was crossed. Only the outcome differs.
     */
    private function alert(Position $position, Rule $rule, string $threshold, float $price): void
    {
        Log::info('Threshold crossed on an alert-only rule', [
            'symbol' => $position->symbol,
            'threshold' => $threshold,
            'price' => $price,
        ]);

        try {
            Notification::send(User::all(), new ThresholdCrossed($position, $rule, $threshold, $price));
        } catch (\Throwable $e) {
            Log::warning('Threshold alert could not be dispatched: '.$e->getMessage());
        }
    }

    public function handle(): void
    {
        if (! Setting::tradingEnabled()) {
            Log::info('Automated trading is paused. Skipping rule evaluation.');

            return;
        }

        // Price sync stops writing snapshots when the session drops, but the scheduler keeps
        // calling this every minute. Evaluating on without a session means trading on whatever
        // price happened to be captured before the stall.
        if (! $this->auth->isAuthenticated()) {
            return;
        }

        $globalRule = Rule::whereNull('position_id')->first();

        // An order that is placed but not yet reconciled has not reduced the position quantity
        // yet, so evaluating against it would sell the same holding twice. During a dry run a
        // simulated order stands in for the sale that would have closed the position, which
        // keeps the simulation honest about how often a rule really fires. Outside a dry run
        // those same records are only history and must not block anything.
        $blockingStatuses = Setting::dryRun()
            ? ['pending', 'placed', 'simulated']
            : ['pending', 'placed'];

        // An order kept from a deleted position has no position_id, and a single NULL in a
        // NOT IN list makes the whole comparison match nothing, which would stop the engine.
        $positionsWithOpenOrders = Order::whereIn('status', $blockingStatuses)
            ->whereNotNull('position_id')
            ->pluck('position_id')
            ->all();

        Position::forActiveAccount()
            ->with('rule')
            ->where('quantity', '>', 0)
            ->whereNotIn('id', $positionsWithOpenOrders)
            ->get()
            ->each(function (Position $position) use ($globalRule) {
                $snapshot = PriceSnapshot::latestFor($position->symbol);

                if (! $snapshot || $snapshot->isStale()) {
                    return;
                }

                $rule = $position->activeRule($globalRule);

                if (! $rule || $rule->isInCooldown($position)) {
                    return;
                }

                // An order sent into a closed market does not execute, and until it is
                // reconciled it also blocks this position from being evaluated at all. An
                // alert has no such problem, so it is not gated on the venue being open.
                if (! $rule->alertsOnly() && $this->marketHours->isOpen($position) === false) {
                    return;
                }

                $price = (float) $snapshot->price;
                $gainPct = $position->gainPct($price);

                $takeProfitHit = $rule->take_profit_pct !== null
                    && $gainPct >= (float) $rule->take_profit_pct;

                $stopLossPrice = $rule->stopLossPrice(
                    $position,
                    $rule->isTrailing() ? PriceSnapshot::peakFor($position->symbol) : null
                );

                $stopLossHit = $stopLossPrice !== null && $price <= $stopLossPrice;

                if (! $takeProfitHit && ! $stopLossHit) {
                    return;
                }

                if ($rule->alertsOnly()) {
                    $this->alert($position, $rule, $takeProfitHit ? 'take_profit' : 'stop_loss', $price);
                } else {
                    $this->placeOrder->handle($position, 'sell', $rule, $rule->sellQuantity($position));
                }

                $position->update(['last_triggered_at' => Carbon::now()]);
            });
    }
}
