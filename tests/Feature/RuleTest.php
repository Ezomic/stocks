<?php

declare(strict_types=1);

use App\Models\Rule;
use Carbon\CarbonImmutable;

it('is not in cooldown when never triggered', function (): void {
    $rule = new Rule(['cooldown_minutes' => 60, 'last_triggered_at' => null]);

    expect($rule->isInCooldown())->toBeFalse();
});

it('is in cooldown when triggered within the cooldown window', function (): void {
    $rule = new Rule([
        'cooldown_minutes' => 60,
        'last_triggered_at' => CarbonImmutable::now()->subMinutes(30),
    ]);

    expect($rule->isInCooldown())->toBeTrue();
});

it('is not in cooldown when triggered after the cooldown window', function (): void {
    $rule = new Rule([
        'cooldown_minutes' => 60,
        'last_triggered_at' => CarbonImmutable::now()->subMinutes(90),
    ]);

    expect($rule->isInCooldown())->toBeFalse();
});

it('identifies global rules', function (): void {
    $global = new Rule(['position_id' => null]);
    $positionRule = new Rule(['position_id' => 42]);

    expect($global->isGlobal())->toBeTrue()
        ->and($positionRule->isGlobal())->toBeFalse();
});
