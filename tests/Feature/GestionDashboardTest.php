<?php

declare(strict_types=1);

use App\Models\User;

test('gestion dashboard loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/gestion/dashboard')
        ->assertOk()
        ->assertSee('Opérations')
        ->assertSee('Dernières adhésions')
        ->assertSee('Derniers dons');
});
