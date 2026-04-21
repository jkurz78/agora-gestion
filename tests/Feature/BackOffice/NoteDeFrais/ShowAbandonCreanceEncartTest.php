<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Enums\TypeCategorie;
use App\Livewire\BackOffice\NoteDeFrais\Show;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\NoteDeFrais;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

// ── Helpers ──────────────────────────────────────────────────────────────────

function abandonBandeauMakeAdmin(Association $association): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, [
        'role' => RoleAssociation::Admin->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => $association->id]);

    return $user;
}

function abandonBandeauBootTenant(Association $association): void
{
    TenantContext::clear();
    TenantContext::boot($association);
    session(['current_association_id' => $association->id]);
}

// ── 1. NDF avec abandon_creance_propose + sous-cat désignée ──────────────────

it('affiche bandeau alert-info avec nom de sous-cat si sous-cat désignée', function (): void {
    $association = Association::factory()->create();
    abandonBandeauBootTenant($association);

    $admin = abandonBandeauMakeAdmin($association);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $catRecette = Categorie::factory()->create([
        'association_id' => $association->id,
        'type' => TypeCategorie::Recette->value,
    ]);
    SousCategorie::factory()->pourAbandonCreance()->create([
        'association_id' => $association->id,
        'categorie_id' => $catRecette->id,
        'nom' => 'Abandon créance test',
        'code_cerfa' => '9876',
    ]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSee('Proposition de don par abandon de créance')
        ->assertSee('Sous-catégorie désignée')
        ->assertSee('Abandon créance test')
        ->assertDontSeeHtml('wire:click="openAbandonForm"')
        ->assertDontSeeHtml("Valider sans constater l'abandon")
        ->assertOk();
});

// ── 2. NDF avec abandon_creance_propose + PAS de sous-cat → warning + lien Usages

it('affiche bandeau alert-info avec warning si pas de sous-cat désignée', function (): void {
    $association = Association::factory()->create();
    abandonBandeauBootTenant($association);

    $admin = abandonBandeauMakeAdmin($association);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertSee('Proposition de don par abandon de créance')
        ->assertSee('Aucune sous-catégorie')
        ->assertSeeHtml(route('parametres.comptabilite.usages'))
        ->assertDontSeeHtml("Valider sans constater l'abandon")
        ->assertOk();
});

// ── 3. NDF sans abandon_creance_propose → bandeau absent, flux normal inchangé ──

it('ne montre pas le bandeau abandon pour une NDF sans intention', function (): void {
    $association = Association::factory()->create();
    abandonBandeauBootTenant($association);

    $admin = abandonBandeauMakeAdmin($association);
    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => false,
    ]);

    $this->actingAs($admin);

    Livewire::test(Show::class, ['noteDeFrais' => $ndf])
        ->assertDontSee('Proposition de don par abandon de créance')
        ->assertDontSeeHtml("Valider sans constater l'abandon")
        ->assertSee('Valider')
        ->assertSee('Rejeter')
        ->assertOk();
});

// ── 4. Policy : Gestionnaire → 403 ───────────────────────────────────────────

it('retourne 403 pour un gestionnaire essayant d\'accéder à une NDF abandon', function (): void {
    $association = Association::factory()->create();
    abandonBandeauBootTenant($association);

    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($association->id, [
        'role' => RoleAssociation::Gestionnaire->value,
        'joined_at' => now(),
    ]);
    $gestionnaire->update(['derniere_association_id' => $association->id]);

    $tiers = Tiers::factory()->create(['association_id' => $association->id]);

    $ndf = NoteDeFrais::factory()->soumise()->create([
        'association_id' => $association->id,
        'tiers_id' => $tiers->id,
        'abandon_creance_propose' => true,
    ]);

    $this->actingAs($gestionnaire)
        ->get(route('comptabilite.ndf.show', $ndf))
        ->assertForbidden();
});
