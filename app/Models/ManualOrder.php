<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ManualOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A buy or sell the owner placed (or intends to place) at the broker by hand and logs here.
 * It is a record only: nothing in this model or its feature ever talks to the broker.
 *
 * @property int $id
 * @property int|null $position_id
 * @property string $symbol
 * @property string $side
 * @property string $quantity
 * @property string|null $price
 * @property string $fees
 * @property string $currency
 * @property Carbon $order_date
 * @property string|null $note
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'position_id',
    'symbol',
    'side',
    'quantity',
    'price',
    'fees',
    'currency',
    'order_date',
    'note',
    'status',
])]
class ManualOrder extends Model
{
    /** @use HasFactory<ManualOrderFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_FILLED = 'filled';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_FILLED, self::STATUS_CANCELLED];

    public const SIDES = ['buy', 'sell'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'price' => 'decimal:4',
            'fees' => 'decimal:4',
            'order_date' => 'date',
        ];
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function total(): ?float
    {
        if ($this->price === null) {
            return null;
        }

        return (float) $this->quantity * (float) $this->price;
    }
}
