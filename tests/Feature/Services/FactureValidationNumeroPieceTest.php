<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\FactureService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->association->update(['facture_compte_bancaire_id' => $this->compte->id]);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $this->user->update(['derniere_association_id' => $this->association->id]);

    TenantContext::boot($this->association);
    $this->actingAs($this->user);

    $this->tiers = Tiers::factory()->create([
        'association_id' => $this->association->id,
        'pour_recettes' => true,
    ]);

    $this->sousCategorie = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(function (): void {
    TenantContext::clear();
});

test('la transaction generee a la validation d une facture MontantManuel porte un numero_piece', function (): void {
    $service = app(FactureService::class);

    $facture = $service->creerManuelleVierge($this->tiers->id);
    $facture->update(['mode_paiement_prevu' => ModePaiement::Virement->value]);

    $service->ajouterLigneManuelle($facture, [
        'libelle' => 'Cotisation mars',
        'prix_unitaire' => 80.0,
        'quantite' => 1.0,
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);

    $service->valider($facture);

    $tg = $facture->fresh()->transactions->first();

    expect($tg)->not->toBeNull();
    expect($tg->numero_piece)->not->toBeNull();
    expect($tg->numero_piece)->not->toBe('');
});
