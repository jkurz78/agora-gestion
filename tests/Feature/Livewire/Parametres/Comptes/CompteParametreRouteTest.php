<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * DC-7 : l'écran « Plan comptable » (/parametres/plan-comptable) remplace
 * l'ancien écran Comptes/Sous-catégories — les deux anciennes URLs
 * redirigent désormais en 301 vers la nouvelle.
 */
beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('[D] route /parametres/comptes redirige 301 vers /parametres/plan-comptable', function (): void {
    $response = $this->get('/parametres/comptes');

    $response->assertStatus(301);
    $response->assertRedirect('/parametres/plan-comptable');
});

it('[E] route /parametres/sous-categories redirige 301 vers /parametres/plan-comptable', function (): void {
    $response = $this->get('/parametres/sous-categories');

    $response->assertStatus(301);
    $response->assertRedirect('/parametres/plan-comptable');
});
