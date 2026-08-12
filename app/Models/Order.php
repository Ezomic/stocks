<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $position_id
 * @property string|null $symbol
 * @property int|null $rule_id
 * @property string $side
 * @property string $quantity
 * @property string|null $remaining_quantity
 * @property string $order_type
 * @property string|null $limit_price
 * @property string $status
 * @property string|null $broker_order_id
 * @property Carbon|null $placed_at
 * @property Carbon|null $filled_at
 * @property Carbon|null $cancel_requested_at
 * @property string|null $fill_price
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'position_id',
    'symbol',
    'rule_id',
    'side',
    'quantity',
    'remaining_quantity',
    'order_type',
    'limit_price',
    'status',
    'broker_order_id',
    'placed_at',
    'filled_at',
    'cancel_requested_at',
    'fill_price',
    'error_message',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'limit_price' => 'decimal:4',
            'fill_price' => 'decimal:4',
            'placed_at' => 'datetime',
            'filled_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    /**
     * How long a placed order may go unconfirmed by the broker before it is treated as
     * abandoned rather than in flight.
     */
    public static function reconcileTimeoutMinutes(): int
    {
        $minutes = config('ibkr.order_reconcile_timeout_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : 30;
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Rule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
