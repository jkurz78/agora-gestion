<?php

use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\Operation;
use App\Models\RapprochementBancaire;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\DepenseService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DepenseService::class);
    $this->compte = CompteBancaire::factory()->create();
});

function makeLockedDepense(CompteBancaire $compte): Depense
{
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'    => $compte->id,
        'statut'       => \App\Enums\StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);
    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'date' => '2025-10-01',
        'montant_total' => 200.00,
        'rapprochement_id' => $rapprochement->id,
    ]);
    // Supprimer les lignes auto-créées par le factory, puis créer exactement une ligne
    $depense->lignes()->forceDelete();
    DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'montant' => 200.00,
    ]);
    return $depense->fresh(['lignes', 'rapprochement']);
}

it('update rejette la modification de date sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => '2025-12-01',
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de compte_id sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $autreCompte = CompteBancaire::factory()->create();
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $autreCompte->id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de montant de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '999.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette la modification de sous_categorie_id de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $autreSousCategorie = SousCategorie::factory()->create();
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $autreSousCategorie->id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null]])
    )->toThrow(\RuntimeException::class);
});

it('update rejette l\'ajout d\'une ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '200.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
        ['sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => '50.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ])
    )->toThrow(\RuntimeException::class);
});

it('update accepte la modification de tiers_id sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $tiers = \App\Models\Tiers::factory()->create();
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
        'tiers_id' => $tiers->id,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh()->tiers_id)->toBe($tiers->id);
});

it('update accepte la modification de libelle et notes sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => 'Libellé modifié',
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
        'notes' => 'Nouvelle note',
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => null, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh()->libelle)->toBe('Libellé modifié');
    expect($depense->fresh()->notes)->toBe('Nouvelle note');
});

it('update accepte la modification d\'operation_id de ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    $operation = Operation::factory()->create();
    $ligne = $depense->lignes->first();

    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [['id' => $ligne->id, 'sous_categorie_id' => $ligne->sous_categorie_id, 'montant' => (string) $ligne->montant, 'operation_id' => $operation->id, 'seance' => null, 'notes' => null]]);

    expect($depense->fresh(['lignes'])->lignes->first()->operation_id)->toBe($operation->id);
});

it('update rejette la suppression d\'une ligne sur pièce verrouillée', function () {
    $depense = makeLockedDepense($this->compte);
    // La dépense a 1 ligne, on soumet 0 lignes
    expect(fn () => $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => $depense->montant_total,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [])
    )->toThrow(\RuntimeException::class);
});
