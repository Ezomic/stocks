<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $symbol
 * @property string $broker_account_id
 * @property string $account_mode
 * @property string $quantity
 * @property string|null $broker_quantity
 * @property string $avg_buy_price
 * @property string $currency
 * @property string $market
 * @property string|null $ibkr_con_id
 * @property string|null $notes
 * @property Carbon|null $last_triggered_at
 * @property Carbon|null $reconciled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property float|null $current_price
 * @property float|null $gain_pct
 * @property float|null $current_value
 * @property bool|null $price_is_stale
 */
#[Fillable([
    'symbol',
    'broker_account_id',
    'account_mode',
    'quantity',
    'avg_buy_price',
    'currency',
    'market',
    'ibkr_con_id',
    'notes',
    'last_triggered_at',
    'broker_quantity',
    'reconciled_at',
])]
class Position extends Model
{
    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'avg_buy_price' => 'decimal:4',
            'broker_quantity' => 'decimal:6',
            'last_triggered_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    /**
     * The positions the app may price and trade right now. The table deliberately holds both
     * paper and live rows, so anything belonging to the other mode or another broker account
     * must never reach the gateway that IBKR_MODE currently points at.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forActiveAccount(Builder $query): void
    {
        $query->where('account_mode', self::activeMode())
            ->where('broker_account_id', self::activeAccountId());
    }

    public static function activeMode(): string
    {
        $mode = config('ibkr.mode');

        return is_string($mode) ? $mode : '';
    }

    public static function activeAccountId(): string
    {
        $accountId = config('ibkr.'.self::activeMode().'.account_id');

        return is_string($accountId) ? $accountId : '';
    }

    /** @return HasOne<Rule, $this> */
    public function rule(): HasOne
    {
        return $this->hasOne(Rule::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasOne<PriceSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PriceSnapshot::class, 'symbol', 'symbol')->latestOfMany('fetched_at');
    }

    /**
     * The rule this position is actually evaluated under, or null when nothing governs it.
     *
     * A rule attached to the position wins whether or not it is active: switching it off is a
     * decision to stop trading this position, not a request to fall back on whatever the
     * global default happens to say.
     */
    public function activeRule(?Rule $globalRule): ?Rule
    {
        $rule = $this->rule ?? $globalRule;

        return $rule !== null && $rule->is_active ? $rule : null;
    }

    /**
     * The broker is the authority on what is actually held. Drift means every rule for this
     * position is being measured against a number that is not real.
     */
    public function hasDrift(): bool
    {
        return $this->broker_quantity !== null
            && abs((float) $this->broker_quantity - (float) $this->quantity) > 0.000001;
    }

    /**
     * Crypto trades in fractions; equities do not.
     */
    public function allowsFractionalQuantity(): bool
    {
        return strtoupper($this->market) === 'CRYPTO';
    }

    public function gainPct(float $currentPrice): float
    {
        if ((float) $this->avg_buy_price === 0.0) {
            return 0.0;
        }

        return ($currentPrice - (float) $this->avg_buy_price) / (float) $this->avg_buy_price * 100;
    }
}
