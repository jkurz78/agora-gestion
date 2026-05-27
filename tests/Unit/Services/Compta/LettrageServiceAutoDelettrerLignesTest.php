<?php

declare(strict_types=1);

use App\Models\Compte;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\LettrageService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

function makeCompteLettrableAD(string $numero = '411'): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => "Compte {$numero}",
        'classe' => (int) substr($numero, 0, 1),
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => true,
        'pour_inscriptions' => false,
    ]);
}

function makeTxAD(): Transaction
{
    return Transaction::factory()->create([
        'association_id' => TenantContext::currentId(),
    ]);
}

function makeLigneAD(Transaction $tx, Compte $compte, float $debit, float $credit, ?string $code = null): TransactionLigne
{
    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'operation_id' => null,
        'seance' => null,
        'montant' => max($debit, $credit),
        'debit' => $debit,
        'credit' => $credit,
        'compte_id' => $compte->id,
        'tiers_id' => null,
        'lettrage_code' => $code,
    ]);
}

// ---------------------------------------------------------------------------
// [A] Tx sans ligne lettrée : retourne 0, aucun audit créé
// ---------------------------------------------------------------------------

test('[A] Tx sans ligne lettrée — autoDelettrerLignesDe retourne 0 sans créer d\'audit', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $compte = makeCompteLettrableAD();
    $tx = makeTxAD();

    // Deux lignes non lettrées
    makeLigneAD($tx, $compte, 100.0, 0.0);
    makeLigneAD($tx, $compte, 0.0, 100.0);

    $service = app(LettrageService::class);

    $auditBefore = DB::table('lettrage_audit')->count();

    $result = $service->autoDelettrerLignesDe($tx, 'motif-test');

    expect($result)->toBe(0)
        ->and(DB::table('lettrage_audit')->count())->toBe($auditBefore);
});

// ---------------------------------------------------------------------------
// [B] Tx avec paire 411 lettrée interne : retourne 1, audit créé avec motif
// ---------------------------------------------------------------------------

test('[B] Tx avec paire 411 lettrée — autoDelettrerLignesDe retourne 1 et crée audit avec motif', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $compte = makeCompteLettrableAD();
    $tx = makeTxAD();

    // Paire lettrée (code interne)
    $code = 'CODE_TEST_PAIRE';
    makeLigneAD($tx, $compte, 100.0, 0.0, $code);
    makeLigneAD($tx, $compte, 0.0, 100.0, $code);

    $service = app(LettrageService::class);

    $result = $service->autoDelettrerLignesDe($tx, 'Auto-délettrage suite à extourne de TX#42');

    expect($result)->toBe(1);

    // Vérifier que le code est bien délettré
    $lignesDeLettrees = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('lettrage_code')
        ->count();
    expect($lignesDeLettrees)->toBe(0);

    // Vérifier l'audit avec le motif
    $audit = DB::table('lettrage_audit')
        ->where('action', 'delettre')
        ->where('lettrage_code', $code)
        ->first();
    expect($audit)->not->toBeNull()
        ->and($audit->motif)->toBe('Auto-délettrage suite à extourne de TX#42');
});

// ---------------------------------------------------------------------------
// [C] Tx avec 2 paires distinctes : retourne 2
// ---------------------------------------------------------------------------

test('[C] Tx avec 2 codes de lettrage distincts — autoDelettrerLignesDe retourne 2', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $compte = makeCompteLettrableAD();
    $tx = makeTxAD();

    // Paire 1
    $code1 = 'CODE_PAIRE_UN';
    makeLigneAD($tx, $compte, 100.0, 0.0, $code1);
    makeLigneAD($tx, $compte, 0.0, 100.0, $code1);

    // Paire 2
    $code2 = 'CODE_PAIRE_DEUX';
    makeLigneAD($tx, $compte, 50.0, 0.0, $code2);
    makeLigneAD($tx, $compte, 0.0, 50.0, $code2);

    $service = app(LettrageService::class);

    $result = $service->autoDelettrerLignesDe($tx, 'motif-deux-paires');

    expect($result)->toBe(2);

    // Toutes les lignes sont déléttrées
    $restantes = TransactionLigne::where('transaction_id', $tx->id)
        ->whereNotNull('lettrage_code')
        ->count();
    expect($restantes)->toBe(0);
});
