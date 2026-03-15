<?php

// tests/Feature/Http/TiersRouteTest.php
declare(strict_types=1);

use App\Models\User;

it('GET /tiers returns 200 for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/tiers')
        ->assertOk()
        ->assertSee('Tiers');
});

it('GET /tiers redirects unauthenticated user', function () {
    $this->get('/tiers')
        ->assertRedirect('/login');
});
