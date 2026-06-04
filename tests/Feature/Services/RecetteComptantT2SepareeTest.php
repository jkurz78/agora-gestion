<?php

declare(strict_types=1);

/**
 * Chantier 2a — Recette comptant saisie live → T1 créance Vente + T2 encaissement Banque séparées.
 *
 * Avant le fix : pourRecetteComptant() produit 1 seule Tx avec 4 lignes (lumpé).
 * Après le fix : pourRecetteACredit() + pourEncaissementCreance() produisent 2 Tx
 *   — T1 (journal=Vente) : 411 D / 7xx C (2 lignes PD + 1 ventilation legacy)
 *   — T2 (journal=Banque) : portage D / 411 C (2 lignes PD)
 *   — le 411 D de T1 est lettré avec le 411 C de T2 (même lettrage_code).
 */

use App\Enums\JournalComptable;
use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RemiseBancaireService;
use App\Services\TransactionService;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $this->service = app(TransactionService::class);
});

// ---------------------------------------------------------------------------
// Helper — données d'une recette comptant chèque
// ---------------------------------------------------------------------------

function recetteChequeLiveData(object $ctx): array
{
    return [
        'data' => [
            'type' => TypeTransaction::Recette->value,
            'date' => '2025-10-15',
            'libelle' => 'Adhésion Jean Dupont',
            'montant_total' => '100.00',
            'mode_paiement' => ModePaiement::Cheque->value,
            'tiers_id' => $ctx->tiers->id,
            'compte_id' => $ctx->compteBancaire->id,
        ],
        'lignes' => [[
            'sous_categorie_id' => $ctx->sc706->id,
            'montant' => '100.00',
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ]],
    ];
}

// ---------------------------------------------------------------------------
// Scénario 2a-1 (RED) — Recette comptant live → T1 + T2 séparées, 411 lettré
// ---------------------------------------------------------------------------

it('[2a-1] recette comptant live produit T1 (Vente, 411D/7xxC) + T2 séparée (Banque, portage/411C), 411 inter-tx lettré', function () {
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    ['data' => $data, 'lignes' => $lignes] = recetteChequeLiveData($this);

    $t1 = $this->service->create($data, $lignes);

    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    // ---- T1 doit être journal=Vente ----
    $t1->refresh();
    expect($t1->journal)->toBe(JournalComptable::Vente, 'T1 journal doit être Vente');

    // ---- T1 doit avoir 2 lignes PD : 411 D + 7xx C (pas de ligne 5112 sur T1) ----
    $lignesT1 = TransactionLigne::where('transaction_id', $t1->id)
        ->whereNotNull('compte_id')
        ->get();

    // Vérifier qu'il n'y a PAS de ligne 5112 sur T1
    $ligne5112T1 = $lignesT1->firstWhere('compte_id', $compte5112->id);
    expect($ligne5112T1)->toBeNull('T1 ne doit PAS avoir de ligne 5112 (portage sur T2 uniquement)');

    // 1 ligne 411 D sur T1 (créance ouverte)
    $ligne411T1 = $lignesT1->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->debit > 0);
    expect($ligne411T1)->not()->toBeNull('T1 doit avoir une ligne 411 D (créance)');
    expect((float) $ligne411T1->debit)->toBe(100.0);

    // ---- T2 séparée doit exister ----
    // La T2 est liée à T1 via le lettrage_code partagé sur les lignes 411.
    // Son journal doit être Banque.
    $ligne411T1->refresh(); // Recharger pour avoir lettrage_code à jour
    expect($ligne411T1->lettrage_code)->not()->toBeNull('La ligne 411 de T1 doit être lettrée (liée à T2)');

    // Retrouver la ligne 411 de T2 via le code de lettrage
    $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();

    expect($ligne411T2)->not()->toBeNull('Une ligne 411 sur une AUTRE Tx (T2) doit partager le code lettrage de T1');

    $t2 = Transaction::findOrFail($ligne411T2->transaction_id);
    expect($t2->journal)->toBe(JournalComptable::Banque, 'T2 journal doit être Banque');

    // ---- T2 doit avoir 2 lignes : portage D + 411 C ----
    $lignesT2 = TransactionLigne::where('transaction_id', $t2->id)
        ->whereNotNull('compte_id')
        ->get();
    expect($lignesT2->count())->toBe(2, 'T2 doit avoir exactement 2 lignes (portage D + 411 C)');

    $lignePortageT2 = $lignesT2->firstWhere('compte_id', $compte5112->id);
    expect($lignePortageT2)->not()->toBeNull('T2 doit avoir une ligne 5112 D (portage chèque)');
    expect((float) $lignePortageT2->debit)->toBe(100.0);
    expect($lignePortageT2->tiers_id)->toBeNull('Invariant FEC : classe 5 sans tiers');

    $ligne411C_T2 = $lignesT2->first(fn ($l) => (int) $l->compte_id === (int) $compte411->id && (float) $l->credit > 0);
    expect($ligne411C_T2)->not()->toBeNull('T2 doit avoir une ligne 411 C');
    expect((float) $ligne411C_T2->credit)->toBe(100.0);

    // ---- Le 411 D de T1 et le 411 C de T2 partagent le même code lettrage ----
    expect($ligne411T1->lettrage_code)->toBe($ligne411T2->lettrage_code, '411 T1 et 411 T2 doivent partager le même lettrage_code');
});

// ---------------------------------------------------------------------------
// Scénario 2a-2 — Réversion : recette comptant passée en non reçue → T2 supprimée, 411 délettré
// ---------------------------------------------------------------------------

it('[2a-2] réversion (mode null) → T2 supprimée, 411 T1 délettré, T1 reste créance pure', function () {
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    ['data' => $data, 'lignes' => $lignes] = recetteChequeLiveData($this);

    $t1 = $this->service->create($data, $lignes);

    $compte411 = compteSysteme('411');

    // Récupérer T2 (via lettrage 411)
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->whereNotNull('lettrage_code')
        ->first();

    expect($ligne411T1)->not()->toBeNull('[Précondition] T1 doit avoir une ligne 411 lettrée');

    $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();

    expect($ligne411T2)->not()->toBeNull('[Précondition] T2 doit exister');
    $t2Id = $ligne411T2->transaction_id;

    // Réversion : repasser la recette en mode null (non reçue)
    $t1->load('lignes');
    $lignesData = $t1->lignes
        ->filter(fn ($l) => $l->sous_categorie_id !== null)
        ->map(fn ($l) => [
            'id' => null,
            'sous_categorie_id' => $l->sous_categorie_id,
            'montant' => $l->montant,
            'operation_id' => $l->operation_id,
            'seance' => $l->seance,
            'notes' => $l->notes,
        ])->values()->toArray();

    $this->service->update($t1, [
        'type' => TypeTransaction::Recette->value,
        'date' => '2025-10-15',
        'libelle' => 'Adhésion Jean Dupont',
        'montant_total' => '100.00',
        'mode_paiement' => null,  // ← réversion vers créance
        'tiers_id' => $this->tiers->id,
        'compte_id' => null,
    ], $lignesData);

    // T2 doit avoir disparu
    $t2Apres = Transaction::find($t2Id);
    expect($t2Apres)->toBeNull('T2 doit être supprimée après réversion');

    // La ligne 411 de T1 doit être délettrée
    $ligne411T1Apres = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->first();
    expect($ligne411T1Apres)->not()->toBeNull('La ligne 411 de T1 doit toujours exister');
    expect($ligne411T1Apres->lettrage_code)->toBeNull('La ligne 411 de T1 doit être délettrée après réversion');
});

// ---------------------------------------------------------------------------
// Scénario 2a-3 — Remise chèque : chèque comptant saisi live, mis dans remise → T4 correcte
// ---------------------------------------------------------------------------

it('[2a-3] remise chèque via TransactionService live → T4 crée depuis le portage 5112 de T2', function () {
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    ['data' => $data, 'lignes' => $lignes] = recetteChequeLiveData($this);

    // Créer la recette comptant chèque via le service live (T1 + T2 séparées après fix)
    $t1 = $this->service->create($data, $lignes);

    $compte411 = compteSysteme('411');
    $compte5112 = compteSysteme('5112');

    // Retrouver T2
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->whereNotNull('lettrage_code')
        ->first();

    expect($ligne411T1)->not()->toBeNull('[Précondition] T1 doit avoir ligne 411 lettrée');

    $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
        ->where('compte_id', $compte411->id)
        ->where('transaction_id', '!=', $t1->id)
        ->first();

    expect($ligne411T2)->not()->toBeNull('[Précondition] T2 doit exister');
    $t2 = Transaction::findOrFail($ligne411T2->transaction_id);

    // Ligne 5112 doit être sur T2 (pas sur T1)
    $ligne5112T2 = TransactionLigne::where('transaction_id', $t2->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($ligne5112T2)->not()->toBeNull('[Précondition] La ligne 5112 doit être sur T2');

    // Créer une remise et comptabiliser
    $remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1001,
        'date' => '2025-10-20',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
        'libelle' => 'Remise chèque test 2a',
        'saisi_par' => $this->user->id,
    ]);

    $remiseService = app(RemiseBancaireService::class);

    // On passe t1 (la source legacy) dans comptabiliser — RemiseBancaireService doit
    // retrouver T2 via trouverEncaissementT2 et utiliser la ligne 5112 de T2
    $remiseService->comptabiliser($remise, [$t1->id]);
    $remise->refresh();

    // T4 doit exister
    $t4 = Transaction::where('remise_id', $remise->id)
        ->where('id', '!=', $t1->id)
        ->where('id', '!=', $t2->id)
        ->first();
    expect($t4)->not()->toBeNull('T4 doit être créée après comptabilisation de la remise');

    // La ligne 5112 de T2 doit être lettrée avec la ligne 5112 de T4
    $ligne5112T2->refresh();
    expect($ligne5112T2->lettrage_code)->not()->toBeNull('La ligne 5112 de T2 doit être lettrée après remise');
});
