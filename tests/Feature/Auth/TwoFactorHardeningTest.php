<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TwoFactorAuthenticator;

function enrolledUser(bool $confirmed = true): User
{
    $authenticator = app(TwoFactorAuthenticator::class);

    $user = User::factory()->create(['password' => 'correct-horse']);

    $user->forceFill([
        'two_factor_secret' => $authenticator->generateSecret(),
        'two_factor_recovery_codes' => $authenticator->generateRecoveryCodes(),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user->refresh();
}

it('does not leave recovery codes on the settings page once two-factor is live', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)->get('/settings')
        ->assertOk()
        ->assertDontSee($user->two_factor_recovery_codes[0]);
});

it('still shows recovery codes while enrolment is unconfirmed', function (): void {
    $user = enrolledUser(confirmed: false);

    $this->actingAs($user)->get('/settings')
        ->assertOk()
        ->assertSee($user->two_factor_recovery_codes[0]);
});

it('reveals recovery codes with the correct password', function (): void {
    $user = enrolledUser();
    $code = $user->two_factor_recovery_codes[0];

    $this->actingAs($user)
        ->post('/settings/two-factor/show-recovery-codes', ['password' => 'correct-horse'])
        ->assertRedirect(route('settings'));

    $this->get('/settings')->assertSee($code);
});

it('refuses to reveal recovery codes with the wrong password', function (): void {
    $user = enrolledUser();
    $code = $user->two_factor_recovery_codes[0];

    $this->actingAs($user)
        ->post('/settings/two-factor/show-recovery-codes', ['password' => 'not-my-password'])
        ->assertSessionHasErrors('password', null, 'twoFactor');

    $this->get('/settings')->assertDontSee($code);
});

it('refuses to reveal recovery codes with no password at all', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->post('/settings/two-factor/show-recovery-codes')
        ->assertSessionHasErrors('password', null, 'twoFactor');
});

it('shows codes once immediately after regenerating them', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->post('/settings/two-factor/recovery-codes', ['password' => 'correct-horse'])
        ->assertRedirect(route('settings'));

    $fresh = $user->fresh()->two_factor_recovery_codes;

    $this->get('/settings')->assertSee($fresh[0]);

    // Second load, no flash left.
    $this->get('/settings')->assertDontSee($fresh[0]);
});

it('will not regenerate recovery codes without the password', function (): void {
    $user = enrolledUser();
    $original = $user->two_factor_recovery_codes;

    $this->actingAs($user)
        ->post('/settings/two-factor/recovery-codes', ['password' => 'not-my-password'])
        ->assertSessionHasErrors('password', null, 'twoFactor');

    expect($user->fresh()->two_factor_recovery_codes)->toBe($original);
});

it('will not turn two-factor off without the password', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->delete('/settings/two-factor', ['password' => 'not-my-password'])
        ->assertSessionHasErrors('password', null, 'twoFactor');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('will not turn two-factor off with no password at all', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->delete('/settings/two-factor')
        ->assertSessionHasErrors('password', null, 'twoFactor');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('turns two-factor off with the correct password', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->delete('/settings/two-factor', ['password' => 'correct-horse'])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('keeps a failed password out of the other forms error bag', function (): void {
    $user = enrolledUser();

    $this->actingAs($user)
        ->delete('/settings/two-factor', ['password' => 'not-my-password'])
        ->assertSessionHasErrors('password', null, 'twoFactor')
        ->assertSessionDoesntHaveErrors('password');
});
