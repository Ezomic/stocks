<?php

declare(strict_types=1);

use App\Models\User;

it('shows the dashboard for authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Portfolio value');
});

it('redirects guests from the dashboard', function (): void {
    $this->get('/')->assertRedirect('/login');
});
