<?php

declare(strict_types=1);

use App\Models\User;

it('redirects guests from the token endpoints', function (): void {
    $this->get('/settings')->assertRedirect('/login');
    $this->post('/settings/api-tokens', ['name' => 'CI'])->assertRedirect('/login');
});

it('lists the users tokens on the settings page without exposing the secret', function (): void {
    $user = User::factory()->create();
    $plainText = $user->createToken('existing')->plainTextToken;

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('existing')
        ->assertDontSee($plainText);
});

it('only lists the acting users own tokens', function (): void {
    $user = User::factory()->create();
    $user->createToken('mine');
    User::factory()->create()->createToken('theirs');

    $this->actingAs($user)
        ->get('/settings')
        ->assertOk()
        ->assertSee('mine')
        ->assertDontSee('theirs');
});

it('creates a token and reveals the plaintext exactly once', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/settings/api-tokens', ['name' => 'CI pipeline']);

    $response->assertRedirect('/settings');
    expect($user->tokens()->count())->toBe(1);

    $plainText = session('createdToken')['plainText'];
    expect($plainText)->toContain('|');

    // The redirect target reveals it once.
    $this->actingAs($user)->get('/settings')->assertOk()->assertSee($plainText);

    // A later load no longer carries the secret.
    $this->actingAs($user)->get('/settings')->assertOk()->assertDontSee($plainText);
});

it('never persists the plaintext token', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/api-tokens', ['name' => 'CI']);

    $plainText = session('createdToken')['plainText'];
    $this->assertDatabaseMissing('personal_access_tokens', ['token' => $plainText]);
});

it('requires a token name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/settings/api-tokens', ['name' => ''])
        ->assertSessionHasErrors('name');

    expect($user->tokens()->count())->toBe(0);
});

it('rejects a duplicate token name for the same user', function (): void {
    $user = User::factory()->create();
    $user->createToken('CI');

    $this->actingAs($user)
        ->post('/settings/api-tokens', ['name' => 'CI'])
        ->assertSessionHasErrors('name');

    expect($user->tokens()->count())->toBe(1);
});

it('allows the same token name for different users', function (): void {
    User::factory()->create()->createToken('CI');
    $second = User::factory()->create();

    $this->actingAs($second)
        ->post('/settings/api-tokens', ['name' => 'CI'])
        ->assertSessionHasNoErrors();

    expect($second->tokens()->count())->toBe(1);
});

it('revokes the users own token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('CI')->accessToken;

    $this->actingAs($user)
        ->delete('/settings/api-tokens/'.$token->getKey())
        ->assertRedirect('/settings');

    expect($user->tokens()->count())->toBe(0);
});

it('cannot revoke another users token', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherToken = $other->createToken('theirs')->accessToken;

    $this->actingAs($user)
        ->delete('/settings/api-tokens/'.$otherToken->getKey())
        ->assertRedirect('/settings');

    expect($other->tokens()->count())->toBe(1);
});

it('authenticates an api request with a created token', function (): void {
    $user = User::factory()->create();
    $plainText = $user->createToken('CI')->plainTextToken;

    $this->getJson('/api/user')->assertUnauthorized();

    $this->getJson('/api/user', ['Authorization' => 'Bearer '.$plainText])
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});
