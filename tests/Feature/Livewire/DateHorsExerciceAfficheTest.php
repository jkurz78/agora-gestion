<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Livewire\TransactionForm;
use App\Livewire\VirementInterneForm;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\VirementInterne;
use Livewire\Livewire;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * La règle : une écriture est refusée SI ET SEULEMENT SI l'exercice de sa
 * date est clôturé. Ni les dates de l'opération, ni l'exercice affiché à
 * l'écran n'entrent dans la règle — deux exercices ouverts se chevauchent
 * plusieurs mois par an (on saisit déjà N+1 pendant que les factures
 * tardives de N arrivent), et un exercice futur qui n'existe pas encore en
 * base n'est pas un motif de refus.
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 (tests/Pest.php) : l'exercice
 * affiché est 2025 (2025-09-01 → 2026-08-31). L'exercice 2024 court du
 * 2024-09-01 au 2025-08-31 — les dates de ce fichier y sont franchement à
 * l'intérieur (15 novembre), jamais sur une borne.
 */

beforeEach(function () {
    $this->setupPartieDoubleContext();
});

it('enregistre une dépense datée hors de l\'exercice affiché quand cet exercice est ouvert', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Ouvert,
    ]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('paiementRecu', false)
        ->set('date', '2024-11-15')
        ->set('libelle', 'Facture tardive de l\'exercice précédent')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $this->compte606->id)
        ->set('lignes.0.montant', '80.00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Transaction::where('libelle', 'Facture tardive de l\'exercice précédent')->exists())->toBeTrue();
});

it('refuse une dépense datée sur un exercice clôturé, avec le message posé sur le champ date', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $html = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('paiementRecu', false)
        ->set('date', '2024-11-15')
        ->set('libelle', 'Facture à tort datée en 2024')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $this->compte606->id)
        ->set('lignes.0.montant', '80.00')
        ->call('save')
        ->assertHasErrors('date')
        ->html();

    // d-block : sans lui, Bootstrap masquerait le message (pas de frère
    // .is-invalid sur x-date-input) — le refus bloquerait en silence.
    expect($html)->toContain('invalid-feedback d-block')
        ->and($html)->toContain('clôturé');

    expect(Transaction::where('libelle', 'Facture à tort datée en 2024')->exists())->toBeFalse();
});

it('accepte une date tombant sur un exercice futur qui n\'existe pas encore en base', function () {
    // Volontairement PAS conditionné à la création d'un exercice 2026 :
    // ExerciceService::assertOuvert() ne fait rien quand la ligne n'existe
    // pas — l'absence de ligne est un formalisme non accompli, pas une
    // interdiction.
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense')
        ->set('paiementRecu', false)
        ->set('date', '2026-11-15')
        ->set('libelle', 'Facture saisie très en avance')
        ->set('tiers_id', $tiers->id)
        ->set('lignes.0.compte_id', (string) $this->compte606->id)
        ->set('lignes.0.montant', '80.00')
        ->call('save')
        ->assertHasNoErrors();

    expect(Exercice::where('annee', 2026)->exists())->toBeFalse();
    expect(Transaction::where('libelle', 'Facture saisie très en avance')->exists())->toBeTrue();
});

it('enregistre un virement interne daté hors de l\'exercice affiché quand cet exercice est ouvert', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Ouvert,
    ]);
    $compteDestination = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    Livewire::test(VirementInterneForm::class)
        ->call('open')
        ->set('date', '2024-11-15')
        ->set('montant', '50.00')
        ->set('compte_source_id', $this->compteBancaire->id)
        ->set('compte_destination_id', $compteDestination->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(VirementInterne::count())->toBe(1);
});

it('refuse un virement interne daté sur un exercice clôturé, avec le message posé sur le champ date', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    $compteDestination = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    $html = Livewire::test(VirementInterneForm::class)
        ->call('open')
        ->set('date', '2024-11-15')
        ->set('montant', '50.00')
        ->set('compte_source_id', $this->compteBancaire->id)
        ->set('compte_destination_id', $compteDestination->id)
        ->call('save')
        ->assertHasErrors('date')
        ->html();

    expect($html)->toContain('invalid-feedback d-block')
        ->and($html)->toContain('clôturé');

    expect(VirementInterne::count())->toBe(0);
});
