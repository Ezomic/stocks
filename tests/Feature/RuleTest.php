<?php

declare(strict_types=1);

use App\Models\Position;
use App\Models\Rule;
use Carbon\CarbonImmutable;

it('is not in cooldown when the position has never triggered', function (): void {
    $rule = new Rule(['cooldown_minutes' => 60]);
    $position = new Position(['last_triggered_at' => null]);

    expect($rule->isInCooldown($position))->toBeFalse();
});

it('is in cooldown when the position triggered within the cooldown window', function (): void {
    $rule = new Rule(['cooldown_minutes' => 60]);
    $position = new Position(['last_triggered_at' => CarbonImmutable::now()->subMinutes(30)]);

    expect($rule->isInCooldown($position))->toBeTrue();
});

it('is not in cooldown when the position triggered before the cooldown window', function (): void {
    $rule = new Rule(['cooldown_minutes' => 60]);
    $position = new Position(['last_triggered_at' => CarbonImmutable::now()->subMinutes(90)]);

    expect($rule->isInCooldown($position))->toBeFalse();
});

it('tracks cooldown separately for each position sharing one rule', function (): void {
    $rule = new Rule(['cooldown_minutes' => 60]);
    $triggered = new Position(['last_triggered_at' => CarbonImmutable::now()->subMinutes(30)]);
    $untouched = new Position(['last_triggered_at' => null]);

    expect($rule->isInCooldown($triggered))->toBeTrue()
        ->and($rule->isInCooldown($untouched))->toBeFalse();
});

it('identifies global rules', function (): void {
    $global = new Rule(['position_id' => null]);
    $positionRule = new Rule(['position_id' => 42]);

    expect($global->isGlobal())->toBeTrue()
        ->and($positionRule->isGlobal())->toBeFalse();
});
