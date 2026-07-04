<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $position_id
 * @property int|null $rule_id
 * @property string $side
 * @property string $quantity
 * @property string $order_type
 * @property string|null $limit_price
 * @property string $status
 * @property string|null $broker_order_id
 * @property Carbon|null $placed_at
 * @property Carbon|null $filled_at
 * @property string|null $fill_price
 * @property string|null $error_message
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'position_id',
    'rule_id',
    'side',
    'quantity',
    'order_type',
    'limit_price',
    'status',
    'broker_order_id',
    'placed_at',
    'filled_at',
    'fill_price',
    'error_message',
])]
class Order extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'limit_price' => 'decimal:4',
            'fill_price' => 'decimal:4',
            'placed_at' => 'datetime',
            'filled_at' => 'datetime',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
