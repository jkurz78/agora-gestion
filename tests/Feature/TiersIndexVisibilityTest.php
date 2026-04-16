<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Models\User;

it('affiche le bouton import pour un admin', function (): void {
    $admin = User::factory()->create(['role' => RoleAssociation::Admin]);

    $this->actingAs($admin)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee('Importer des tiers');
});

it('affiche le bouton import pour un comptable', function (): void {
    $comptable = User::factory()->create(['role' => RoleAssociation::Comptable]);

    $this->actingAs($comptable)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertSee('Importer des tiers');
});

it('masque le bouton import pour un utilisateur en consultation', function (): void {
    $consultation = User::factory()->create(['role' => RoleAssociation::Consultation]);

    $this->actingAs($consultation)
        ->get(route('tiers.index'))
        ->assertOk()
        ->assertDontSee('Importer des tiers');
});
