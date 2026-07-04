<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
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
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'limit_price' => 'decimal:4',
        'fill_price' => 'decimal:4',
        'placed_at' => 'datetime',
        'filled_at' => 'datetime',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }
}
