<?php

// tests/Feature/Http/TiersRouteTest.php
declare(strict_types=1);

use App\Models\User;

it('GET /compta/tiers returns 200 for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/compta/tiers')
        ->assertOk()
        ->assertSee('Tiers');
});

it('GET /compta/tiers redirects unauthenticated user', function () {
    $this->get('/compta/tiers')
        ->assertRedirect('/login');
});
