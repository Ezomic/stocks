<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;

function attemptLogin(string $email, string $password = 'wrong-password'): TestResponse
{
    return test()->post('/login', ['email' => $email, 'password' => $password]);
}

it('allows five failed attempts before locking out', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse']);

    for ($i = 0; $i < 5; $i++) {
        attemptLogin($user->email)->assertSessionHasErrors('email');
        expect(flashedError('email'))->toBe('These credentials do not match our records.');
    }

    attemptLogin($user->email);

    expect(flashedError('email'))->toContain('Too many login attempts');
});

it('keeps rejecting the correct password once locked out', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse']);

    for ($i = 0; $i < 6; $i++) {
        attemptLogin($user->email);
    }

    attemptLogin($user->email, 'correct-horse');

    expect(flashedError('email'))->toContain('Too many login attempts')
        ->and(auth()->check())->toBeFalse();
});

it('clears the limiter after a successful login', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse']);

    attemptLogin($user->email);
    attemptLogin($user->email);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/');

    expect(auth()->check())->toBeTrue();

    auth()->logout();

    attemptLogin($user->email)->assertSessionHasErrors('email');
    expect(flashedError('email'))->toBe('These credentials do not match our records.');
});

it('throttles each email separately', function (): void {
    $user = User::factory()->create(['password' => 'correct-horse']);

    for ($i = 0; $i < 6; $i++) {
        attemptLogin($user->email);
    }

    attemptLogin('someone-else@example.com');

    expect(flashedError('email'))->toBe('These credentials do not match our records.');
});

it('still validates the request shape', function (): void {
    $this->post('/login', ['email' => 'not-an-email', 'password' => ''])
        ->assertSessionHasErrors(['email', 'password']);
});
