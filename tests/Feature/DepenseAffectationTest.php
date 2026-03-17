<?php

use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\DepenseLigneAffectation;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\DepenseService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(DepenseService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create();
    $this->op2 = Operation::factory()->create();
});

function makeDepenseAvecLigne(CompteBancaire $compte, float $montant = 20000.00): DepenseLigne
{
    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => $montant,
    ]);
    // Supprimer les lignes auto-créées par le factory, puis créer exactement une ligne
    $depense->lignes()->forceDelete();

    return DepenseLigne::factory()->create([
        'depense_id' => $depense->id,
        'montant' => $montant,
    ]);
}

it('affecterLigne crée des affectations en remplacement', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(2);
    expect((float) DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->sum('montant'))->toBe(20000.0);
});

it('affecterLigne accepte une seule affectation', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 5000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(1);
});

it('affecterLigne rejette si la somme ne correspond pas au montant de la ligne', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]))->toThrow(RuntimeException::class, 'somme');
});

it('affecterLigne rejette si un montant est nul ou négatif', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '0.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]))->toThrow(RuntimeException::class);
});

it('affecterLigne remplace les affectations existantes', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(2);
});

it('affecterLigne ne modifie pas la ligne source ni le montant_total', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);
    $montantAvant = $ligne->montant;
    $totalAvant = $ligne->depense->montant_total;

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    expect($ligne->fresh()->montant)->toBe($montantAvant);
    expect($ligne->depense->fresh()->montant_total)->toBe($totalAvant);
});

it('supprimerAffectations supprime toutes les affectations d\'une ligne', function () {
    $ligne = makeDepenseAvecLigne($this->compte, 20000.00);
    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->supprimerAffectations($ligne);

    expect(DepenseLigneAffectation::where('depense_ligne_id', $ligne->id)->count())->toBe(0);
});

it('update() sur pièce non verrouillée préserve les affectations existantes', function () {
    $sousCategorie = SousCategorie::factory()->create();

    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'montant_total' => 100.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = $depense->lignes()->create([
        'sous_categorie_id' => $sousCategorie->id,
        'operation_id' => null,
        'montant' => 100.00,
        'seance' => null,
        'notes' => null,
    ]);

    $ligne->affectations()->create([
        'operation_id' => $this->op1->id,
        'seance' => null,
        'montant' => 60.00,
        'notes' => null,
    ]);
    $ligne->affectations()->create([
        'operation_id' => null,
        'seance' => null,
        'montant' => 40.00,
        'notes' => null,
    ]);

    $depense->refresh();
    $this->service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => 'Nouveau libellé',
        'montant_total' => 100.00,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $sousCategorie->id, 'montant' => '100.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    $newLigne = $depense->fresh(['lignes.affectations'])->lignes->first();
    expect($newLigne->affectations)->toHaveCount(2);
    expect((float) $newLigne->affectations->sum('montant'))->toBe(100.0);
});

it('update() non verrouillé supprime les affectations quand le montant de ligne change', function () {
    $compte = CompteBancaire::factory()->create();
    $sousCategorie = SousCategorie::factory()->create();
    $op = Operation::factory()->create();

    $depense = Depense::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => 100.00,
    ]);
    $depense->lignes()->forceDelete();
    $ligne = $depense->lignes()->create([
        'sous_categorie_id' => $sousCategorie->id,
        'operation_id' => null,
        'montant' => 100.00,
        'seance' => null,
        'notes' => null,
    ]);
    $ligne->affectations()->create([
        'operation_id' => $op->id,
        'seance' => null,
        'montant' => 100.00,
        'notes' => null,
    ]);

    // Update with a different montant — affectations must be dropped
    $service = app(DepenseService::class);
    $service->update($depense, [
        'date' => $depense->date->format('Y-m-d'),
        'libelle' => $depense->libelle,
        'montant_total' => 150.00,
        'mode_paiement' => $depense->mode_paiement->value,
        'compte_id' => $depense->compte_id,
        'reference' => $depense->reference,
    ], [
        ['id' => $ligne->id, 'sous_categorie_id' => $sousCategorie->id, 'montant' => '150.00', 'operation_id' => null, 'seance' => null, 'notes' => null],
    ]);

    $newLigne = $depense->fresh(['lignes.affectations'])->lignes->first();
    expect($newLigne->affectations)->toHaveCount(0);
});
