<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Services\ReglementOperationService;
use Carbon\Carbon;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'sous_categorie_id' => $this->sc706->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Atelier PHP',
    ]);
    $this->seance = Seance::create([
        'association_id' => $this->association->id,
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-11-20',
    ]);

    $this->service = app(ReglementOperationService::class);
    $this->date = Carbon::parse('2025-11-20');
});

// ---------------------------------------------------------------------------
// Helper local
// ---------------------------------------------------------------------------

function creerT1AvecLigne411(object $ctx, float $montant = 100.00): Transaction
{
    $tiers = Tiers::factory()->create(['association_id' => $ctx->association->id]);
    $participant = Participant::create([
        'association_id' => $ctx->association->id,
        'tiers_id' => (int) $tiers->id,
        'operation_id' => (int) $ctx->operation->id,
        'date_inscription' => now(),
    ]);
    Reglement::create([
        'participant_id' => (int) $participant->id,
        'seance_id' => (int) $ctx->seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => $montant,
    ]);

    $ctx->service->comptabiliserSeance($ctx->seance, (int) $ctx->compteBancaire->id, $ctx->date);

    return Transaction::where('statut_reglement', StatutReglement::EnAttente->value)->firstOrFail();
}

// ---------------------------------------------------------------------------
// Scénario I1 : encaisserSiNonEncaisse — premier appel génère T2
// ---------------------------------------------------------------------------

it('[I1] encaisserSiNonEncaisse — premier appel génère exactement 1 T2', function () {
    $t1 = creerT1AvecLigne411($this, 100.00);

    $this->service->encaisserSiNonEncaisse($t1);

    // 1 T2 créée
    expect(Transaction::count())->toBe(2);

    // statut_reglement de T1 INCHANGÉ (helper ne touche pas au statut)
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::EnAttente);
});

// ---------------------------------------------------------------------------
// Scénario I2 : encaisserSiNonEncaisse — deuxième appel = no-op silencieux
// ---------------------------------------------------------------------------

it('[I2] encaisserSiNonEncaisse — deuxième appel idempotent (no-op, pas de 2ᵉ T2, pas d\'exception)', function () {
    $t1 = creerT1AvecLigne411($this, 100.00);

    // Premier appel
    $this->service->encaisserSiNonEncaisse($t1);
    expect(Transaction::count())->toBe(2);

    $compte411 = compteSysteme('411');
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->firstOrFail();
    expect($ligne411T1->lettrage_code)->not->toBeNull(); // bien lettrée après 1er appel

    // Deuxième appel — doit être un no-op (pas d'exception, pas de nouvelle T2)
    expect(fn () => $this->service->encaisserSiNonEncaisse($t1->fresh()))->not->toThrow(Throwable::class);

    expect(Transaction::count())->toBe(2); // toujours 2, aucune 3e transaction
});

// ---------------------------------------------------------------------------
// Scénario I3 : encaisserSiNonEncaisse — no-op si T1 sans ligne 411 (legacy)
// ---------------------------------------------------------------------------

it('[I3] encaisserSiNonEncaisse — no-op silencieux si T1 legacy sans ligne 411', function () {
    // Crée une transaction brute sans ligne 411 (transaction legacy)
    $txLegacy = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'statut_reglement' => StatutReglement::EnAttente->value,
        'compte_id' => $this->compteBancaire->id,
    ]);

    // Doit être un no-op sans exception
    expect(fn () => $this->service->encaisserSiNonEncaisse($txLegacy))->not->toThrow(Throwable::class);

    // Aucune T2 générée
    expect(Transaction::count())->toBe(1);
});
