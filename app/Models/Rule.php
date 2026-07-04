<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Rule extends Model
{
    use HasFactory;

    protected $fillable = [
        'position_id',
        'take_profit_pct',
        'stop_loss_pct',
        'is_active',
        'cooldown_minutes',
        'last_triggered_at',
    ];

    protected $casts = [
        'take_profit_pct' => 'decimal:2',
        'stop_loss_pct' => 'decimal:2',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function isInCooldown(): bool
    {
        if ($this->last_triggered_at === null) {
            return false;
        }

        return $this->last_triggered_at->addMinutes($this->cooldown_minutes)->isFuture();
    }

    public function isGlobal(): bool
    {
        return $this->position_id === null;
    }
}
