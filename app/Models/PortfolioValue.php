<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $recorded_on
 * @property string $currency
 * @property string $value
 * @property string $cost
 * @property int $positions
 */
#[Fillable(['recorded_on', 'currency', 'value', 'cost', 'positions'])]
class PortfolioValue extends Model
{
    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'value' => 'decimal:4',
            'cost' => 'decimal:4',
        ];
    }

    public function gainPct(): ?float
    {
        $cost = (float) $this->cost;

        return $cost > 0.0 ? ((float) $this->value - $cost) / $cost * 100 : null;
    }
}
