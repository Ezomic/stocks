<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'symbol',
        'price',
        'currency',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'fetched_at' => 'datetime',
    ];

    public static function latestFor(string $symbol): ?self
    {
        return self::where('symbol', $symbol)
            ->orderByDesc('fetched_at')
            ->first();
    }
}
