<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $position_id
 * @property string $action
 * @property string|null $take_profit_pct
 * @property string|null $stop_loss_pct
 * @property string $stop_loss_type
 * @property string $sell_pct
 * @property string|null $buy_below_pct
 * @property string|null $buy_amount
 * @property string|null $max_position_value
 * @property bool $is_active
 * @property int $cooldown_minutes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'position_id',
    'action',
    'take_profit_pct',
    'stop_loss_pct',
    'stop_loss_type',
    'sell_pct',
    'buy_below_pct',
    'buy_amount',
    'max_position_value',
    'is_active',
    'cooldown_minutes',
])]
class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'take_profit_pct' => 'decimal:2',
            'stop_loss_pct' => 'decimal:2',
            'sell_pct' => 'decimal:2',
            'buy_below_pct' => 'decimal:2',
            'buy_amount' => 'decimal:2',
            'max_position_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Cooldown is tracked on the position, not on the rule. A global rule is shared by every
     * position, so a rule-level timestamp let the first position to trigger silence the
     * take-profit and stop-loss of every other position for the whole cooldown window.
     */
    public function isInCooldown(Position $position): bool
    {
        if ($position->last_triggered_at === null) {
            return false;
        }

        return $position->last_triggered_at->addMinutes($this->cooldown_minutes)->isFuture();
    }

    public function alertsOnly(): bool
    {
        return $this->action === 'notify';
    }

    public function isTrailing(): bool
    {
        return $this->stop_loss_type === 'trailing';
    }

    /**
     * The price at which the stop fires. A trailing stop hangs off the highest price seen
     * rather than the entry price, so a position that ran up and gave it all back is caught
     * where a fixed stop would still be sitting above the entry doing nothing.
     */
    public function stopLossPrice(Position $position, ?float $peakPrice): ?float
    {
        if ($this->stop_loss_pct === null) {
            return null;
        }

        $factor = 1 - ((float) $this->stop_loss_pct / 100);

        if (! $this->isTrailing()) {
            return (float) $position->avg_buy_price * $factor;
        }

        return $peakPrice === null ? null : $peakPrice * $factor;
    }

    public function takeProfitPrice(Position $position): ?float
    {
        if ($this->take_profit_pct === null) {
            return null;
        }

        return (float) $position->avg_buy_price * (1 + ((float) $this->take_profit_pct / 100));
    }

    /**
     * How much to sell when this rule fires. A percentage of what is held right now rather
     * than a share count, because the quantity shrinks as a ladder works through the position.
     *
     * Returns zero when the step is too small to express in whole units. The caller reports
     * that rather than rounding it away, because a step that silently does nothing looks
     * identical to a rule that never triggered.
     */
    public function sellQuantity(Position $position): float
    {
        $held = (float) $position->quantity;
        $wanted = $held * ((float) $this->sell_pct / 100);

        if ($position->allowsFractionalQuantity()) {
            return min($wanted, $held);
        }

        return min(floor($wanted), $held);
    }

    public function isPartial(): bool
    {
        return (float) $this->sell_pct < 100.0;
    }

    public function buys(): bool
    {
        return $this->buy_below_pct !== null && $this->buy_amount !== null;
    }

    public function buyTriggerPrice(Position $position): ?float
    {
        if ($this->buy_below_pct === null) {
            return null;
        }

        return (float) $position->avg_buy_price * (1 - ((float) $this->buy_below_pct / 100));
    }

    /**
     * How much to buy at this price, in units.
     *
     * The sizing model is a fixed cash amount per trigger, clamped by whatever headroom is
     * left under max_position_value. Returns zero when the position is already at its cap or
     * the headroom no longer buys a whole unit, which the caller reports rather than rounding
     * away.
     */
    public function buyQuantity(Position $position, float $price): float
    {
        if (! $this->buys() || $price <= 0.0) {
            return 0.0;
        }

        $spend = (float) $this->buy_amount;

        if ($this->max_position_value !== null) {
            $headroom = (float) $this->max_position_value - ((float) $position->quantity * $price);
            $spend = min($spend, max($headroom, 0.0));
        }

        $units = $spend / $price;

        return $position->allowsFractionalQuantity() ? $units : floor($units);
    }

    public function isGlobal(): bool
    {
        return $this->position_id === null;
    }
}
