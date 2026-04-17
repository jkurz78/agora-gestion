<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('affiche le bouton import pour un admin', function (): void {
    $admin = User::factory()->create();
    $admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    $this->actingAs($admin)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee('Importer des tiers');
});

it('affiche le bouton import pour un comptable', function (): void {
    $comptable = User::factory()->create();
    $comptable->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    $this->actingAs($comptable)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee('Importer des tiers');
});

it('masque le bouton import pour un utilisateur en consultation', function (): void {
    $consultation = User::factory()->create();
    $consultation->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    $this->actingAs($consultation)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertDontSee('Importer des tiers');
});
