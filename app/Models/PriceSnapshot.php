<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $symbol
 * @property string $price
 * @property string $currency
 * @property string $source
 * @property Carbon $fetched_at
 */
#[Fillable([
    'symbol',
    'price',
    'currency',
    'source',
    'fetched_at',
])]
class PriceSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'fetched_at' => 'datetime',
        ];
    }

    public static function latestFor(string $symbol): ?self
    {
        return self::where('symbol', $symbol)
            ->orderByDesc('fetched_at')
            ->first();
    }

    public function isStale(): bool
    {
        return $this->fetched_at->isBefore(Carbon::now()->subMinutes(self::maxAgeMinutes()));
    }

    public static function maxAgeMinutes(): int
    {
        $minutes = config('ibkr.max_price_age_minutes');

        return is_numeric($minutes) ? (int) $minutes : 5;
    }
}
