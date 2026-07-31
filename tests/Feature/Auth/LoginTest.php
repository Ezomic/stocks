<?php

declare(strict_types=1);

use App\Models\User;

it('redirects unauthenticated users to login', function (): void {
    $this->get('/')->assertRedirect('/login');
});

it('shows login form', function (): void {
    $this->get('/login')->assertOk()->assertSee('Login');
});

it('logs in with valid credentials', function (): void {
    $user = User::factory()->create(['password' => bcrypt('secret')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret'])
        ->assertRedirect('/');
});

it('rejects invalid credentials', function (): void {
    User::factory()->create(['email' => 'test@example.com', 'password' => bcrypt('correct')]);

    $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors();
});

it('logs out authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/login');
});
