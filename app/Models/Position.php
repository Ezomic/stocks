<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'symbol',
        'broker_account_id',
        'account_mode',
        'quantity',
        'avg_buy_price',
        'currency',
        'market',
        'ibkr_con_id',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'avg_buy_price' => 'decimal:4',
    ];

    public function rule(): HasOne
    {
        return $this->hasOne(Rule::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

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
