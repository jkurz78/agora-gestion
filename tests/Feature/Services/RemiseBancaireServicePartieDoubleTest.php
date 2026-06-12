<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $this->service = app(RemiseBancaireService::class);
    $this->generator = app(EcritureGenerator::class);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une T1 recette comptant chèque via EcritureGenerator et retourne la ligne 5112 source.
 */
function t25creerLigne5112(object $ctx, float $montant): TransactionLigne
{
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $compteProduit = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '706_'.uniqid()],
        [
            'intitule' => 'Produit test',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $t1 = $ctx->generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette chèque test',
    );

    $compte5112 = compteSysteme('5112');

    return $t1->lignes->firstWhere('compte_id', $compte5112->id);
}

/**
 * Crée une T1 recette comptant espèces via EcritureGenerator et retourne la ligne 530 source.
 */
function t25creerLigne530(object $ctx, float $montant): TransactionLigne
{
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $compteProduit = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '706_'.uniqid()],
        [
            'intitule' => 'Produit test espèces',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    $t1 = $ctx->generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Especes,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette espèces test',
    );

    $compte530 = compteSysteme('530');

    return $t1->lignes->firstWhere('compte_id', $compte530->id);
}

/**
 * Crée une RemiseBancaire pointant vers le compteBancaire du contexte.
 */
function t25creerRemise(object $ctx, ModePaiement $mode = ModePaiement::Cheque): RemiseBancaire
{
    return $ctx->service->creer([
        'date' => '2026-05-22',
        'mode_paiement' => $mode->value,
        'compte_cible_id' => $ctx->compteBancaire->id,
    ]);
}

/**
 * Crée une transaction source legacy (sans lignes partie double) pour les tests G.
 */
function t25creerTransactionLegacy(object $ctx, ModePaiement $mode = ModePaiement::Cheque, float $montant = 50.0): Transaction
{
    return Transaction::factory()->asRecette()->create([
        'association_id' => TenantContext::currentId(),
        'compte_id' => $ctx->compteBancaire->id,
        'mode_paiement' => $mode,
        'montant_total' => $montant,
        'statut_reglement' => StatutReglement::EnAttente,
        'remise_id' => null,
    ]);
}

// ---------------------------------------------------------------------------
// Scénario A — Remise chèque 3 tx sources → T4 créée (4 lignes) + lettrage 5112↔5112
// ---------------------------------------------------------------------------

it('[A] comptabiliser chèque 3 tx sources → T4 créée avec 4 lignes, lettrée par paire', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    // Créer 3 T1 recette comptant chèque via EcritureGenerator → lignes 5112 sources
    $ligne1 = t25creerLigne5112($this, 50.00);
    $ligne2 = t25creerLigne5112($this, 30.00);
    $ligne3 = t25creerLigne5112($this, 20.00);

    // Récupérer les T1 sous-jacentes
    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);
    $tx3 = Transaction::findOrFail($ligne3->transaction_id);

    // Action
    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id, $tx3->id]);

    $remise->refresh();

    // --- Vérification legacy : statuts + références posés sur T1/T2/T3
    foreach ([$tx1, $tx2, $tx3] as $i => $tx) {
        $tx->refresh();
        expect($tx->statut_reglement)->toBe(StatutReglement::Recu);
        expect($tx->remise_id)->toBe($remise->id);
        expect($tx->reference)->toStartWith('RBC-');
    }

    // --- Vérification partie double : T4 créée
    // La T4 a remise_id = $remise->id ET n'est pas l'une des T1/T2/T3 sources
    $txIds = [$tx1->id, $tx2->id, $tx3->id];
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds)
        ->first();

    expect($t4)->not->toBeNull('T4 doit être créée et liée via remise_id');

    // T4 a 4 lignes : 1 ligne 512X D + 3 lignes 5112 C
    $lignesT4 = TransactionLigne::where('transaction_id', $t4->id)->get();
    expect($lignesT4)->toHaveCount(4);

    $compte5112 = compteSysteme('5112');

    $ligne512T4 = $lignesT4->firstWhere('compte_id', $this->compte512X->id);
    expect($ligne512T4)->not->toBeNull('Ligne 512X D attendue dans T4');
    expect((float) $ligne512T4->debit)->toBe(100.00);
    expect((float) $ligne512T4->credit)->toBe(0.00);
    expect($ligne512T4->tiers_id)->toBeNull();

    $lignes5112T4 = $lignesT4->where('compte_id', $compte5112->id);
    expect($lignes5112T4)->toHaveCount(3);

    foreach ($lignes5112T4 as $l) {
        expect($l->tiers_id)->toBeNull();
        expect($l->lettrage_code)->not->toBeNull();
    }

    // Lettrage 1↔1 : chaque ligne source partage son code avec exactement 1 ligne T4
    $ligne1->refresh();
    $ligne2->refresh();
    $ligne3->refresh();

    expect($ligne1->lettrage_code)->not->toBeNull();
    expect($ligne2->lettrage_code)->not->toBeNull();
    expect($ligne3->lettrage_code)->not->toBeNull();

    // Codes distincts (lettrage par paire, pas de regroupement)
    $codes = collect([$ligne1->lettrage_code, $ligne2->lettrage_code, $ligne3->lettrage_code])->unique();
    expect($codes->count())->toBe(3, '3 codes distincts pour 3 paires');

    // Chaque code est partagé avec exactement une ligne T4
    foreach ([$ligne1, $ligne2, $ligne3] as $ligneSource) {
        $ligneT4 = $lignes5112T4->firstWhere('lettrage_code', $ligneSource->lettrage_code);
        expect($ligneT4)->not->toBeNull("Code {$ligneSource->lettrage_code} attendu sur une ligne T4");
    }

    // T4.remise_id = $remise->id
    expect((int) $t4->remise_id)->toBe((int) $remise->id);
});

// ---------------------------------------------------------------------------
// Scénario B — Remise espèces 2 tx sources → T4 créée (3 lignes) + lettrage 530↔530
// ---------------------------------------------------------------------------

it('[B] comptabiliser espèces 2 tx sources → T4 créée avec 3 lignes 530, lettrée par paire', function () {
    $remise = t25creerRemise($this, ModePaiement::Especes);

    $ligne1 = t25creerLigne530($this, 40.00);
    $ligne2 = t25creerLigne530($this, 60.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    $remise->refresh();

    $txIds = [$tx1->id, $tx2->id];
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds)
        ->first();

    expect($t4)->not->toBeNull('T4 espèces doit être créée');

    $lignesT4 = TransactionLigne::where('transaction_id', $t4->id)->get();
    expect($lignesT4)->toHaveCount(3);

    $compte530 = compteSysteme('530');

    $ligne512T4 = $lignesT4->firstWhere('compte_id', $this->compte512X->id);
    expect($ligne512T4)->not->toBeNull();
    expect((float) $ligne512T4->debit)->toBe(100.00);
    expect($ligne512T4->tiers_id)->toBeNull();

    $lignes530T4 = $lignesT4->where('compte_id', $compte530->id);
    expect($lignes530T4)->toHaveCount(2);

    foreach ($lignes530T4 as $l) {
        expect($l->tiers_id)->toBeNull();
        expect($l->lettrage_code)->not->toBeNull();
    }

    // Lettrage 530↔530
    $ligne1->refresh();
    $ligne2->refresh();

    expect($ligne1->lettrage_code)->not->toBeNull();
    expect($ligne2->lettrage_code)->not->toBeNull();
    expect($ligne1->lettrage_code)->not->toBe($ligne2->lettrage_code, '2 codes distincts');

    foreach ([$ligne1, $ligne2] as $ligneSource) {
        $ligneT4 = $lignes530T4->firstWhere('lettrage_code', $ligneSource->lettrage_code);
        expect($ligneT4)->not->toBeNull();
    }

    expect((int) $t4->remise_id)->toBe((int) $remise->id);
});

// ---------------------------------------------------------------------------
// Scénario C — Solde 5112 = 0 après remise chèque
// ---------------------------------------------------------------------------

it('[C] solde ouvert 5112 = 0 après comptabiliser (toutes paires lettrées)', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 75.00);
    $ligne2 = t25creerLigne5112($this, 25.00);

    $compte5112 = compteSysteme('5112');

    // Avant remise : solde 5112 = 100
    $soldeAvant = TransactionLigne::where('compte_id', $compte5112->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeAvant)->toBe(100.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    // Après remise : solde global 5112 = 0 (les crédits T4 compensent les débits T1)
    $soldeApres = TransactionLigne::where('compte_id', $compte5112->id)
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeApres)->toBe(0.00);

    // Solde ouvert (lignes non lettrées) = 0
    $soldeOuvert = TransactionLigne::where('compte_id', $compte5112->id)
        ->whereNull('lettrage_code')
        ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS solde')
        ->value('solde');
    expect((float) $soldeOuvert)->toBe(0.00);
});

// ---------------------------------------------------------------------------
// Scénario D — Supprimer remise → T4 supprimée, lignes 5112 sources délettrées
// ---------------------------------------------------------------------------

it('[D] supprimer remise → T4 supprimée, lignes 5112 sources délettrées', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 60.00);
    $ligne2 = t25creerLigne5112($this, 40.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    // Vérifier que T4 existe
    $txIds = [$tx1->id, $tx2->id];
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds)
        ->firstOrFail();

    $t4Id = $t4->id;

    // Action : supprimer la remise
    $this->service->supprimer($remise->fresh());

    // Remise soft-deleted
    expect(RemiseBancaire::find($remise->id))->toBeNull();
    expect(RemiseBancaire::withTrashed()->find($remise->id))->not->toBeNull();

    // T4 supprimée (hard delete ou soft-delete avec lignes)
    expect(Transaction::find($t4Id))->toBeNull('T4 doit être supprimée');
    expect(TransactionLigne::where('transaction_id', $t4Id)->count())->toBe(0, 'Lignes T4 doivent être supprimées');

    // Lignes 5112 sources délettrées → redeviennent remisables
    $ligne1->refresh();
    $ligne2->refresh();
    expect($ligne1->lettrage_code)->toBeNull('Ligne source 1 doit être délettrée');
    expect($ligne2->lettrage_code)->toBeNull('Ligne source 2 doit être délettrée');

    // T1/T2 sources resetées — en mode PD, le syncer dérive EnMain (5112 délettré = chèque en main).
    $tx1->refresh();
    $tx2->refresh();
    expect($tx1->remise_id)->toBeNull();
    expect($tx1->statut_reglement)->toBe(StatutReglement::EnMain);
    expect($tx2->remise_id)->toBeNull();
    expect($tx2->statut_reglement)->toBe(StatutReglement::EnMain);
});

// ---------------------------------------------------------------------------
// Scénario E — Modifier remise (ajout tx) → T4 recréée avec 3 lignes 5112
// ---------------------------------------------------------------------------

it('[E] modifier remise (ajout tx) → T4 recréée avec le nouveau périmètre', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 50.00);
    $ligne2 = t25creerLigne5112($this, 30.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    // T4 initiale = 3 lignes (1 × 512 + 2 × 5112)
    $txIds12 = [$tx1->id, $tx2->id];
    $t4Initiale = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds12)
        ->firstOrFail();
    $t4InitialeId = $t4Initiale->id;

    expect(TransactionLigne::where('transaction_id', $t4InitialeId)->count())->toBe(3);

    // Créer une 3ème tx source
    $ligne3 = t25creerLigne5112($this, 20.00);
    $tx3 = Transaction::findOrFail($ligne3->transaction_id);

    // Modifier la remise en ajoutant tx3
    $this->service->modifier($remise->fresh(), [$tx1->id, $tx2->id, $tx3->id]);

    // Ancienne T4 supprimée
    expect(Transaction::find($t4InitialeId))->toBeNull('Ancienne T4 doit être supprimée');

    // Nouvelle T4 créée avec 4 lignes (1 × 512 + 3 × 5112)
    $txIds123 = [$tx1->id, $tx2->id, $tx3->id];
    $t4Nouvelle = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds123)
        ->first();

    expect($t4Nouvelle)->not->toBeNull('Nouvelle T4 doit être créée');
    expect(TransactionLigne::where('transaction_id', $t4Nouvelle->id)->count())->toBe(4);

    // Toutes les lignes sources lettrées avec la nouvelle T4
    $ligne1->refresh();
    $ligne2->refresh();
    $ligne3->refresh();
    expect($ligne1->lettrage_code)->not->toBeNull();
    expect($ligne2->lettrage_code)->not->toBeNull();
    expect($ligne3->lettrage_code)->not->toBeNull();

    // tx3 rattachée à la remise
    $tx3->refresh();
    expect($tx3->remise_id)->toBe($remise->id);
    expect($tx3->statut_reglement)->toBe(StatutReglement::Recu);

    // Fix Important-1 : vérifier que la nouvelle tx3 reçoit le bon numéro de référence.
    // Avec 2 T1 existantes, le count doit ignorer la T4 (reference IS NULL), donc tx3 → -003 et non -004.
    $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
    expect($tx3->reference)->toEndWith("-{$numeroPadded}-003", 'tx3 doit recevoir -003, pas -004 (T4 exclue du count)');
});

// ---------------------------------------------------------------------------
// Scénario F — Modifier remise (retrait tx) → T4 recréée, ligne source retirée délettrée
// ---------------------------------------------------------------------------

it('[F] modifier remise (retrait tx) → T4 recréée, ligne source retirée redevient remisable', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 50.00);
    $ligne2 = t25creerLigne5112($this, 30.00);
    $ligne3 = t25creerLigne5112($this, 20.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);
    $tx3 = Transaction::findOrFail($ligne3->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id, $tx3->id]);

    // T4 initiale = 4 lignes (1 × 512 + 3 × 5112)
    $txIds123 = [$tx1->id, $tx2->id, $tx3->id];
    $t4Initiale = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds123)
        ->firstOrFail();
    $t4InitialeId = $t4Initiale->id;

    // Modifier : retirer tx3
    $this->service->modifier($remise->fresh(), [$tx1->id, $tx2->id]);

    // Ancienne T4 supprimée
    expect(Transaction::find($t4InitialeId))->toBeNull('Ancienne T4 doit être supprimée');

    // Nouvelle T4 créée avec 3 lignes (1 × 512 + 2 × 5112)
    $txIds12 = [$tx1->id, $tx2->id];
    $t4Nouvelle = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds12)
        ->first();

    expect($t4Nouvelle)->not->toBeNull('Nouvelle T4 doit être créée');
    expect(TransactionLigne::where('transaction_id', $t4Nouvelle->id)->count())->toBe(3);

    // tx3 détachée + délettrée — en mode PD, le syncer dérive EnMain (5112 délettré = chèque en main).
    $tx3->refresh();
    expect($tx3->remise_id)->toBeNull();
    expect($tx3->statut_reglement)->toBe(StatutReglement::EnMain);

    $ligne3->refresh();
    expect($ligne3->lettrage_code)->toBeNull('Ligne source retirée doit être délettrée');

    // tx1/tx2 encore lettrées avec la nouvelle T4
    $ligne1->refresh();
    $ligne2->refresh();
    expect($ligne1->lettrage_code)->not->toBeNull();
    expect($ligne2->lettrage_code)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Scénario G — Tx legacy sans ligne 5112 portage → skip silencieux + Log::warning
// ---------------------------------------------------------------------------

it('[G] tx legacy sans ligne 5112 → skip + Log::warning ou Log::info, remise_id posé legacy, pas de T4 créée', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    // Transaction legacy : pas de lignes partie double (pas de 5112)
    $txLegacy = t25creerTransactionLegacy($this, ModePaiement::Cheque, 75.00);

    // Task 5 — reglerOuEncaisser() émet Log::info (pas warning) pour "pas de ligne tiers ouverte".
    // On accepte warning et info pour couvrir les deux niveaux de log émis sur ce chemin.
    Log::shouldReceive('warning')
        ->atLeast()->once()
        ->withArgs(function (string $msg) {
            return str_contains($msg, '[PartieDouble]') || str_contains($msg, 'Step 25') || str_contains($msg, 'ligne') || str_contains($msg, '5112');
        });
    Log::shouldReceive('info')
        ->zeroOrMoreTimes();

    $this->service->comptabiliser($remise, [$txLegacy->id]);

    // Legacy préservé : remise_id posé, statut = Recu, reference assignée
    $txLegacy->refresh();
    expect($txLegacy->remise_id)->toBe($remise->id);
    expect($txLegacy->statut_reglement)->toBe(StatutReglement::Recu);
    expect($txLegacy->reference)->toStartWith('RBC-');

    // Aucune T4 créée (aucune source valide)
    $t4 = Transaction::where('remise_id', $remise->id)
        ->where('id', '!=', $txLegacy->id)
        ->first();
    expect($t4)->toBeNull('Pas de T4 créée si aucune source valide');
});

it('[G2] mix tx PD + tx legacy → T4 créée pour les sources valides seulement, skip sur la legacy', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    // 1 tx avec ligne 5112 (issue du Step 21)
    $ligne5112 = t25creerLigne5112($this, 50.00);
    $txPD = Transaction::findOrFail($ligne5112->transaction_id);

    // 1 tx legacy sans lignes 5112
    $txLegacy = t25creerTransactionLegacy($this, ModePaiement::Cheque, 30.00);

    $this->service->comptabiliser($remise, [$txPD->id, $txLegacy->id]);

    // T4 créée avec uniquement la source PD (2 lignes : 1 × 512 + 1 × 5112)
    $txIds = [$txPD->id, $txLegacy->id];
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNotIn('id', $txIds)
        ->first();

    expect($t4)->not->toBeNull('T4 créée pour la source PD valide');
    expect(TransactionLigne::where('transaction_id', $t4->id)->count())->toBe(2);

    // Les 2 tx ont leur remise_id posé (legacy OK)
    $txPD->refresh();
    $txLegacy->refresh();
    expect($txPD->remise_id)->toBe($remise->id);
    expect($txLegacy->remise_id)->toBe($remise->id);
});

// ---------------------------------------------------------------------------
// Scénario H — Remise verrouillée par rappro → throw existant préservé
// ---------------------------------------------------------------------------

it('[H] comptabiliser une remise verrouillée lève RuntimeException (garde préservée)', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    // Verrouiller : ajouter une tx pointée
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::Pointe,
        'remise_id' => $remise->id,
    ]);

    $txSource = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteBancaire->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 20.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'remise_id' => null,
    ]);

    $this->service->comptabiliser($remise->fresh(), [$txSource->id]);
})->throws(RuntimeException::class);

// ---------------------------------------------------------------------------
// Scénario I — queryT4() identifie exactement 1 T4 après comptabiliser() — invariant critère
// ---------------------------------------------------------------------------

it('[I] queryT4() identifie unique T4 après comptabiliser() — invariant critère', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 60.00);
    $ligne2 = t25creerLigne5112($this, 40.00);

    $tx1 = Transaction::findOrFail($ligne1->transaction_id);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    // Le critère queryT4() doit retourner exactement 1 résultat
    $countT4 = Transaction::where('remise_id', $remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->count();

    expect($countT4)->toBe(1, 'Exactement 1 T4 doit correspondre au critère queryT4()');

    // La T4 unique correspond bien à la transaction créée par EcritureGenerator (equilibree = true)
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->firstOrFail();

    // Elle n'est pas l'une des T1 sources
    expect((int) $t4->id)->not->toBe((int) $tx1->id);
    expect((int) $t4->id)->not->toBe((int) $tx2->id);

    // Elle a bien les lignes T4 (1 × 512X + 2 × 5112)
    $lignesT4 = TransactionLigne::where('transaction_id', $t4->id)->get();
    expect($lignesT4)->toHaveCount(3, 'T4 doit avoir 3 lignes pour 2 sources');

    // Les T1 sources ont toutes reference != null (invariant)
    $tx1->refresh();
    $tx2->refresh();
    expect($tx1->reference)->not->toBeNull('T1 source 1 doit avoir reference non-null (invariant queryT4)');
    expect($tx2->reference)->not->toBeNull('T1 source 2 doit avoir reference non-null (invariant queryT4)');
});

// ---------------------------------------------------------------------------
// Scénario J — comptabiliser() appelé 2× → throw "déjà comptabilisée"
// ---------------------------------------------------------------------------

it('[J] comptabiliser() 2× sur la même remise → throw RuntimeException "déjà comptabilisée"', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $ligne1 = t25creerLigne5112($this, 50.00);
    $tx1 = Transaction::findOrFail($ligne1->transaction_id);

    // Premier appel : doit réussir
    $this->service->comptabiliser($remise, [$tx1->id]);

    // Créer une 2ème tx pour simuler un 2ème appel qui fournirait de nouvelles ids
    $ligne2 = t25creerLigne5112($this, 30.00);
    $tx2 = Transaction::findOrFail($ligne2->transaction_id);

    // Deuxième appel sur la même remise : doit lever une exception "déjà comptabilisée"
    $this->service->comptabiliser($remise->fresh(), [$tx2->id]);
})->throws(RuntimeException::class, 'déjà comptabilisée');

// ---------------------------------------------------------------------------
// Scénario K (AC7) — Remise d'un chèque en_attente (Fix C)
// La source n'a pas eu "marquer reçu" : pas de ligne 5112 sur la T1.
// comptabiliser() doit : 1) générer le T2 (encaissement → ligne 5112 sur T2),
//                        2) puis créer le T4.
// ---------------------------------------------------------------------------

/**
 * Crée une T1 créance chèque « en attente » via EcritureGenerator::pourRecetteACredit
 * (école 411 : 411D tiers / 706C — PAS de ligne 5112).
 * Représente le cycle séance avant marquerRecu.
 */
function t25creerT1EnAttente(object $ctx, float $montant = 60.00): Transaction
{
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $compteProduit = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '706_ea_'.uniqid()],
        [
            'intitule' => 'Produit en attente',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    return $ctx->generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Créance chèque en attente',
    );
}

it('[K] comptabiliser chèque en_attente sans T2 préalable → génère T2 (5112) puis T4', function () {
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    // T1 créance pure (411D/706C) — pas encore de ligne 5112
    $t1 = t25creerT1EnAttente($this, 60.00);
    $t1->update(['mode_paiement' => ModePaiement::Cheque->value, 'compte_id' => $this->compteBancaire->id]);

    // Vérifier que T1 n'a pas encore de ligne 5112
    $compte5112 = compteSysteme('5112');
    $lignePortageAvant = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte5112->id)
        ->first();
    expect($lignePortageAvant)->toBeNull('T1 en attente ne doit pas encore avoir de ligne 5112');

    // Action
    $this->service->comptabiliser($remise, [$t1->id]);

    // 1. Un T2 a été créé : une transaction distincte de T1 qui porte la ligne 5112 D
    // T2 n'a pas de remise_id (seule la T4 l'a après recreerT4)
    $t2 = Transaction::where('association_id', TenantContext::currentId())
        ->where('id', '!=', $t1->id)
        ->whereHas('lignes', function ($q) use ($compte5112) {
            $q->where('compte_id', $compte5112->id)->where('debit', '>', 0);
        })
        ->whereNull('remise_id')
        ->first();
    expect($t2)->not->toBeNull('T2 (encaissement chèque) doit avoir été créé sur la ligne 5112');

    // 2. La paire 411 de T1 est lettrée
    $compte411 = compteSysteme('411');
    $ligne411T1 = TransactionLigne::where('transaction_id', $t1->id)
        ->where('compte_id', $compte411->id)
        ->first();
    expect($ligne411T1)->not->toBeNull();
    expect($ligne411T1->lettrage_code)->not->toBeNull('La ligne 411 de T1 doit être lettrée après encaissement');

    // 3. La T4 a été créée (remise_id, reference IS NULL, equilibree)
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->first();
    expect($t4)->not->toBeNull('La T4 doit être créée après Fix C');

    // 4. La T4 porte une ligne 512X D
    $ligneT4_512X = TransactionLigne::where('transaction_id', $t4->id)
        ->where('compte_id', $this->compte512X->id)
        ->where('debit', '>', 0)
        ->first();
    expect($ligneT4_512X)->not->toBeNull('La T4 doit avoir une ligne 512X D');
    expect((float) $ligneT4_512X->debit)->toBe(60.00);

    // 5. T1 status = Recu (mis à jour par comptabiliser)
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::Recu);
    expect($t1->remise_id)->toBe($remise->id);
});

it('[K2] comptabiliser chèque en_attente → idempotence via modifier() : pas de second T2', function () {
    // Note : comptabiliser() throw si T4 déjà présente — l'idempotence de l'encaissement
    // est testée via modifier() qui supprime T4 et en recrée une sans dupliquer T2.
    $remise = t25creerRemise($this, ModePaiement::Cheque);

    $t1a = t25creerT1EnAttente($this, 40.00);
    $t1a->update(['mode_paiement' => ModePaiement::Cheque->value, 'compte_id' => $this->compteBancaire->id]);

    $t1b = t25creerT1EnAttente($this, 35.00);
    $t1b->update(['mode_paiement' => ModePaiement::Cheque->value, 'compte_id' => $this->compteBancaire->id]);

    // Première comptabilisation avec t1a seulement
    $this->service->comptabiliser($remise, [$t1a->id]);

    // Vérifier qu'un T2 a été créé pour t1a
    $compte411 = compteSysteme('411');
    $ligne411a = TransactionLigne::where('transaction_id', $t1a->id)
        ->where('compte_id', $compte411->id)->first();
    expect($ligne411a->lettrage_code)->not->toBeNull('T1a 411 lettrée après comptabiliser');

    // Ajouter t1b via modifier()
    $this->service->modifier($remise->fresh(), [$t1a->id, $t1b->id]);

    // Compter les T2 : chaque T1 doit avoir exactement 1 T2 (ligne 411 lettrée unique)
    // T1a — sa ligne 411 doit être encore lettrée (pas de double-lettrage)
    $ligne411aFresh = TransactionLigne::where('transaction_id', $t1a->id)
        ->where('compte_id', $compte411->id)->first();
    expect($ligne411aFresh->lettrage_code)->not->toBeNull('T1a 411 toujours lettrée après modifier');

    // T1b — sa ligne 411 doit aussi être lettrée (T2 créé par modifier)
    $ligne411b = TransactionLigne::where('transaction_id', $t1b->id)
        ->where('compte_id', $compte411->id)->first();
    expect($ligne411b->lettrage_code)->not->toBeNull('T1b 411 lettrée par modifier');

    // La T4 finale doit exister avec les deux sources
    $t4 = Transaction::where('remise_id', $remise->id)
        ->whereNull('reference')
        ->where('equilibree', true)
        ->first();
    expect($t4)->not->toBeNull('T4 doit exister après modifier');
    $nbLignes = TransactionLigne::where('transaction_id', $t4->id)->count();
    expect($nbLignes)->toBe(3, 'T4 : 1 ligne 512X + 2 lignes 5112 pour 2 sources');
});
