<?php

declare(strict_types=1);

use App\Models\User;

test('adherents page loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/adherents')
        ->assertOk()
        ->assertSee('Adhérent');
});

test('legacy /membres redirects to /gestion/adherents', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/membres')
        ->assertRedirect('/gestion/adherents');
});
