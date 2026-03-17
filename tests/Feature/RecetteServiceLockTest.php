<?php

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\User;
use App\Services\RecetteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RecetteService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->sousCategorie = SousCategorie::factory()->create();
});

function makeLockedRecette(CompteBancaire $compte): Recette
{
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 100.00,
        'rapprochement_id' => $rapprochement->id,
    ]);
    // Supprimer les lignes auto-créées par le factory, puis créer exactement une ligne
    $recette->lignes()->forceDelete();
    RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'montant' => 100.00,
    ]);

    return $recette->fresh(['lignes', 'rapprochement']);
}

it('update rejette la modification de date sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => '2025-11-01',
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de compte_id sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $autreCompte = CompteBancaire::factory()->create();
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $autreCompte->id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de montant de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette la modification de sous_categorie_id de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $autreSousCategorie = SousCategorie::factory()->create();
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $autreSousCategorie->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(RuntimeException::class);
});

it('update rejette l\'ajout d\'une ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ])
    )->toThrow(RuntimeException::class);
});

it('update accepte la modification de libelle et notes sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => 'Nouveau libellé',
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
        'notes' => 'Nouvelle note',
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh()->libelle)->toBe('Nouveau libellé');
    expect($recette->fresh()->notes)->toBe('Nouvelle note');
});

it('update accepte la modification d\'operation_id de ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $operation = Operation::factory()->create();
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh(['lignes'])->lignes->first()->operation_id)->toBe($operation->id);
});

it('update accepte la modification de tiers_id sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    $tiers = Tiers::factory()->create();
    $ligne = $recette->lignes->first();

    $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
        'tiers_id' => $tiers->id,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($recette->fresh()->tiers_id)->toBe($tiers->id);
});

it('update rejette la suppression d\'une ligne sur pièce verrouillée', function () {
    $recette = makeLockedRecette($this->compte);
    expect(fn () => $this->service->update($recette, [
        'date' => $recette->date->format('Y-m-d'),
        'libelle' => $recette->libelle,
        'montant_total' => $recette->montant_total,
        'mode_paiement' => $recette->mode_paiement->value,
        'compte_id' => $recette->compte_id,
        'reference' => $recette->reference,
    ], [])
    )->toThrow(RuntimeException::class);
});
