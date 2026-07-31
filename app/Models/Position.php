<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PositionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $avg_buy_price
 * @property string $currency
 * @property string $market
 * @property string|null $ibkr_con_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property float|null $current_price
 * @property float|null $gain_pct
 * @property float|null $current_value
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
        ];
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

    public function gainPct(float $currentPrice): float
    {
        if ((float) $this->avg_buy_price === 0.0) {
            return 0.0;
        }

        return ($currentPrice - (float) $this->avg_buy_price) / (float) $this->avg_buy_price * 100;
    }
}
