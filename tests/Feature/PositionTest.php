<?php

declare(strict_types=1);

use App\Models\Position;

it('calculates gain percentage correctly', function (): void {
    $position = new Position(['avg_buy_price' => '100.00']);

    expect($position->gainPct(110.0))->toBe(10.0)
        ->and($position->gainPct(90.0))->toBe(-10.0)
        ->and($position->gainPct(100.0))->toBe(0.0);
});

it('returns zero gain when avg buy price is zero', function (): void {
    $position = new Position(['avg_buy_price' => '0.00']);

    expect($position->gainPct(100.0))->toBe(0.0);
});
