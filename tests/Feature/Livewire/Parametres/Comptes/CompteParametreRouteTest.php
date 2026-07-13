<?php

declare(strict_types=1);

use App\Livewire\PlanComptable;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

/**
 * L'écran « Plan comptable » est l'entrée unique des paramètres comptables.
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

it('la page hôte comptes rend le plan comptable', function (): void {
    $this->view('parametres.comptes.index')
        ->assertSeeLivewire(PlanComptable::class);
});
