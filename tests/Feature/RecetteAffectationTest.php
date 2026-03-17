<?php

use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\RecetteLigneAffectation;
use App\Models\User;
use App\Services\RecetteService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->service = app(RecetteService::class);
    $this->compte = CompteBancaire::factory()->create();
    $this->op1 = Operation::factory()->create();
    $this->op2 = Operation::factory()->create();
});

function makeRecetteAvecLigne(CompteBancaire $compte, float $montant = 20000.00): RecetteLigne
{
    $recette = Recette::factory()->create([
        'compte_id' => $compte->id,
        'montant_total' => $montant,
    ]);
    // Supprimer les lignes auto-créées par le factory, puis créer exactement une ligne
    $recette->lignes()->forceDelete();
    return RecetteLigne::factory()->create([
        'recette_id' => $recette->id,
        'montant' => $montant,
    ]);
}

it('affecterLigne crée des affectations en remplacement', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(2);
    expect((float) RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->sum('montant'))->toBe(20000.0);
});

it('affecterLigne accepte une seule affectation', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 5000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(1);
});

it('affecterLigne rejette si la somme ne correspond pas au montant de la ligne', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '5000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class, 'somme');
});

it('affecterLigne rejette si un montant est nul ou négatif', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    expect(fn () => $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '0.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]))->toThrow(\RuntimeException::class);
});

it('affecterLigne remplace les affectations existantes', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '8000.00', 'notes' => null],
        ['operation_id' => $this->op2->id, 'seance' => null, 'montant' => '12000.00', 'notes' => null],
    ]);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(2);
});

it('affecterLigne ne modifie pas la ligne source ni le montant_total', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);
    $montantAvant = $ligne->montant;
    $totalAvant = $ligne->recette->montant_total;

    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    expect($ligne->fresh()->montant)->toBe($montantAvant);
    expect($ligne->recette->fresh()->montant_total)->toBe($totalAvant);
});

it('supprimerAffectations supprime toutes les affectations d\'une ligne', function () {
    $ligne = makeRecetteAvecLigne($this->compte, 20000.00);
    $this->service->affecterLigne($ligne, [
        ['operation_id' => $this->op1->id, 'seance' => null, 'montant' => '20000.00', 'notes' => null],
    ]);

    $this->service->supprimerAffectations($ligne);

    expect(RecetteLigneAffectation::where('recette_ligne_id', $ligne->id)->count())->toBe(0);
});
