<?php

declare(strict_types=1);

use App\Models\User;

test('adherents page loads successfully', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/tiers/adherents')
        ->assertOk()
        ->assertSee('Adhérent');
});

test('legacy /membres redirects to /tiers/adherents', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)
        ->get('/membres')
        ->assertRedirect('/tiers/adherents');
});
