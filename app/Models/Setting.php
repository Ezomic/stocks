<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const TRADING_ENABLED = 'trading_enabled';

    public static function bool(string $key, bool $default): bool
    {
        $setting = self::where('key', $key)->first();

        return $setting === null ? $default : $setting->value === '1';
    }

    public static function setBool(string $key, bool $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value ? '1' : '0']);
    }

    /**
     * Trading is on unless it has been explicitly switched off, so an install that has never
     * touched the switch behaves exactly as it did before the switch existed.
     */
    public static function tradingEnabled(): bool
    {
        return self::bool(self::TRADING_ENABLED, true);
    }
}
