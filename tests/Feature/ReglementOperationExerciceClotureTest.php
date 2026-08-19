<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutExercice;
use App\Exceptions\ExerciceCloturedException;
use App\Livewire\ReglementTable;
use App\Models\Exercice;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Services\ReglementOperationService;
use Carbon\Carbon;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

/*
 * Un exercice clôturé n'accepte plus aucune écriture — y compris depuis
 * l'onglet Règlements d'une opération.
 *
 * Ce parcours construisait ses transactions par Transaction::create() sans
 * jamais interroger le statut de l'exercice : un règlement daté sur un
 * exercice clos y était enregistré en silence, alors que la même écriture
 * saisie manuellement ou depuis l'onglet Encadrants était refusée. La règle
 * comptable ne dépend pas de l'écran par lequel on passe.
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 : l'exercice affiché est 2025
 * (2025-09-01 → 2026-08-31). L'exercice 2024 court du 2024-09-01 au
 * 2025-08-31.
 */

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation clôture',
    ]);
    $this->seance = Seance::create([
        'association_id' => $this->association->id,
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-11-15',
    ]);

    $this->service = app(ReglementOperationService::class);
});

/** Un règlement prévu, sans transaction, sur la séance du test. */
function reglementClotureTest(object $ctx, float $montant = 100.0): Reglement
{
    $tiers = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $participant = Participant::create([
        'association_id' => $ctx->association->id,
        'tiers_id' => (int) $tiers->id,
        'operation_id' => (int) $ctx->operation->id,
        'date_inscription' => now(),
    ]);

    return Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $ctx->seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => $montant,
    ]);
}

it('refuse de comptabiliser une séance sur un exercice clôturé', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    reglementClotureTest($this);

    $this->service->comptabiliserSeance(
        $this->seance,
        (int) $this->compteBancaire->id,
        Carbon::parse('2025-01-15'),
    );
})->throws(ExerciceCloturedException::class);

it('n\'écrit rien du tout quand l\'exercice est clôturé', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    reglementClotureTest($this);
    reglementClotureTest($this);

    $avant = Transaction::count();

    try {
        $this->service->comptabiliserSeance(
            $this->seance,
            (int) $this->compteBancaire->id,
            Carbon::parse('2025-01-15'),
        );
    } catch (ExerciceCloturedException) {
        // attendu
    }

    // La garde précède la boucle : aucun règlement du lot n'est comptabilisé.
    expect(Transaction::count())->toBe($avant);
});

it('accepte la comptabilisation quand l\'exercice de la date est ouvert', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2025,
        'statut' => StatutExercice::Ouvert,
    ]);
    reglementClotureTest($this);

    $avant = Transaction::count();

    $this->service->comptabiliserSeance(
        $this->seance,
        (int) $this->compteBancaire->id,
        Carbon::parse('2025-11-15'),
    );

    expect(Transaction::count())->toBeGreaterThan($avant);
});

it('affiche le message de clôture dans la modale au lieu d\'une erreur serveur', function () {
    Exercice::create([
        'association_id' => $this->association->id,
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    reglementClotureTest($this);

    $avant = Transaction::count();

    Livewire\Livewire::test(ReglementTable::class, ['operation' => $this->operation])
        ->call('ouvrirComptabiliser', (int) $this->seance->id)
        ->set('comptabiliserCompteId', (int) $this->compteBancaire->id)
        ->set('comptabiliserDate', '2025-01-15')
        ->call('comptabiliserSeance')
        ->assertHasErrors('comptabiliserDate')
        ->assertSee('est clôturé')
        // La modale reste ouverte : l'utilisateur corrige la date sur place.
        ->assertSet('showComptabiliserModal', true);

    expect(Transaction::count())->toBe($avant);
});
