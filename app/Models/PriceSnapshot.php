<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
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

    /**
     * The highest price on record for a symbol since a given moment. Snapshots are pruned, so
     * this is never older than the retention window. Callers show the window alongside the
     * number so the difference is visible rather than assumed.
     */
    public static function peakFor(string $symbol, ?CarbonInterface $since = null): ?float
    {
        $peak = self::where('symbol', $symbol)
            ->when($since, fn ($query) => $query->where('fetched_at', '>=', $since))
            ->max('price');

        return is_numeric($peak) ? (float) $peak : null;
    }

    public static function earliestFor(string $symbol, ?CarbonInterface $since = null): ?Carbon
    {
        $earliest = self::where('symbol', $symbol)
            ->when($since, fn ($query) => $query->where('fetched_at', '>=', $since))
            ->min('fetched_at');

        return is_string($earliest) ? Carbon::parse($earliest) : null;
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

    public static function retentionDays(): int
    {
        $days = config('ibkr.snapshot_retention_days');

        return is_numeric($days) ? (int) $days : 30;
    }
}
