<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use PragmaRX\Google2FA\Google2FA;

function userWithTwoFactor(bool $confirmed = true): User
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

function currentOtp(User $user): string
{
    return app(Google2FA::class)->getCurrentOtp((string) $user->two_factor_secret);
}

it('sends a user with two-factor enabled to the challenge instead of logging them in', function (): void {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/two-factor-challenge');

    expect(auth()->check())->toBeFalse()
        ->and(session('two_factor.user_id'))->toBe($user->id);
});

it('logs a user straight in when two-factor is off', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse']);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
});

it('ignores a secret that was never confirmed', function (): void {
    $user = userWithTwoFactor(confirmed: false);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
});

it('logs in with a valid authenticator code', function (): void {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    $this->post('/two-factor-challenge', ['code' => currentOtp($user)])
        ->assertRedirect('/');

    expect(auth()->id())->toBe($user->id)
        ->and(session('two_factor.user_id'))->toBeNull();
});

it('rejects an invalid authenticator code', function (): void {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('logs in with a recovery code and burns it', function (): void {
    $user = userWithTwoFactor();
    $recoveryCode = $user->two_factor_recovery_codes[0];

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertRedirect('/');

    expect(auth()->id())->toBe($user->id)
        ->and($user->fresh()->two_factor_recovery_codes)->not->toContain($recoveryCode)
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('refuses a recovery code that has already been used', function (): void {
    $user = userWithTwoFactor();
    $recoveryCode = $user->two_factor_recovery_codes[0];

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode]);
    auth()->logout();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
        ->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('throttles repeated challenge attempts', function (): void {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->post('/two-factor-challenge', ['code' => currentOtp($user)]);

    expect(flashedError('code'))->toContain('Too many attempts')
        ->and(auth()->check())->toBeFalse();
});

it('sends someone with no pending challenge back to login', function (): void {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/login');
});

it('shows the challenge form when a login is pending', function (): void {
    $user = userWithTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    $this->get('/two-factor-challenge')
        ->assertOk()
        ->assertSee('Two-factor authentication');
});

it('enables two-factor from settings and confirms it with a code', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/two-factor')->assertRedirect(route('settings'));

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_recovery_codes)->toHaveCount(8);

    $this->post('/settings/two-factor/confirm', ['code' => currentOtp($user)])
        ->assertRedirect(route('settings'));

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('does not confirm two-factor with a wrong code', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/two-factor');
    $this->post('/settings/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('shows the qr code and secret while enrolment is pending', function (): void {
    $user = userWithTwoFactor(confirmed: false);

    $this->actingAs($user)->get('/settings')
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee((string) $user->two_factor_secret);
});

it('regenerates recovery codes', function (): void {
    $user = userWithTwoFactor();
    $original = $user->two_factor_recovery_codes;

    $this->actingAs($user)->post('/settings/two-factor/recovery-codes')
        ->assertRedirect(route('settings'));

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(8)
        ->and($user->fresh()->two_factor_recovery_codes)->not->toBe($original);
});

it('turns two-factor off again', function (): void {
    $user = userWithTwoFactor();

    $this->actingAs($user)->delete('/settings/two-factor')->assertRedirect(route('settings'));

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse();
});

it('keeps the two-factor endpoints behind authentication', function (): void {
    $this->post('/settings/two-factor')->assertRedirect('/login');
    $this->post('/settings/two-factor/confirm', ['code' => '123456'])->assertRedirect('/login');
    $this->post('/settings/two-factor/recovery-codes')->assertRedirect('/login');
    $this->delete('/settings/two-factor')->assertRedirect('/login');
});

it('stores the secret and recovery codes encrypted at rest', function (): void {
    $user = userWithTwoFactor();

    $raw = DB::table('users')->where('id', $user->id)->first();

    expect($raw->two_factor_secret)->not->toBe($user->two_factor_secret)
        ->and($raw->two_factor_recovery_codes)->not->toContain($user->two_factor_recovery_codes[0]);
});
