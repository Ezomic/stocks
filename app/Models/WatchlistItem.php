<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A symbol being followed but not held. Everything else in the app hangs off a position, which
 * is backwards for deciding what to buy in the first place.
 *
 * @property int $id
 * @property string $symbol
 * @property string $ibkr_con_id
 * @property string $currency
 * @property string $market
 * @property string|null $notes
 * @property Carbon $created_at
 */
#[Fillable(['symbol', 'ibkr_con_id', 'currency', 'market', 'notes'])]
class WatchlistItem extends Model
{
    /** @return HasOne<PriceSnapshot, $this> */
    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(PriceSnapshot::class, 'symbol', 'symbol')->latestOfMany('fetched_at');
    }
}
