<?php

declare(strict_types=1);

use App\Exceptions\Compta\LettrageInexistantException;
use App\Exceptions\Compta\LigneNonLettreeException;
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

/**
 * Crée un compte lettrable pour le tenant courant.
 */
function makeCompteLettrable(string $numero = '411'): Compte
{
    return Compte::create([
        'association_id' => TenantContext::currentId(),
        'numero_pcg' => $numero,
        'intitule' => "Compte {$numero}",
        'classe' => (int) substr($numero, 0, 1),
        'lettrable' => true,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
}

/**
 * Crée une paire de lignes équilibrées (debit 100 + credit 100) sur le même compte.
 * Retourne [$ligne1, $ligne2].
 *
 * @return array{0: TransactionLigne, 1: TransactionLigne}
 */
function makePaireEquilibree(Compte $compte, float $montant = 100.00): array
{
    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $l1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => $montant,
        'credit' => '0.00',
        'montant' => $montant,
        'sous_categorie_id' => null,
    ]);

    $l2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => $montant,
        'montant' => $montant,
        'sous_categorie_id' => null,
    ]);

    return [$l1, $l2];
}

// ---------------------------------------------------------------------------
// Test 1 : delettrer($code) remet lettrage_code = NULL sur toutes les lignes
// ---------------------------------------------------------------------------
test('delettrer un code existant → toutes les lignes du groupe passent à lettrage_code NULL', function () {
    $compte = makeCompteLettrable('411');
    [$l1, $l2] = makePaireEquilibree($compte);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$l1, $l2]));

    // Précondition : les lignes sont bien lettrées
    expect(TransactionLigne::find($l1->id)->lettrage_code)->toBe($code);
    expect(TransactionLigne::find($l2->id)->lettrage_code)->toBe($code);

    $service->delettrer($code);

    // Postcondition : lettrage effacé
    expect(TransactionLigne::find($l1->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($l2->id)->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 2 : audit action='delettre' créé avec snapshot exact des IDs délettrés
// ---------------------------------------------------------------------------
test('delettrer crée une ligne audit action=delettre avec snapshot IDs, motif, user_id, compte_id, association_id', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $compte = makeCompteLettrable('4111');
    [$l1, $l2] = makePaireEquilibree($compte, 150.00);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$l1, $l2]), motif: 'motif-lettrage');

    $service->delettrer($code, 'motif-delettre');

    $audit = DB::table('lettrage_audit')
        ->where('lettrage_code', $code)
        ->where('action', 'delettre')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->compte_id)->toBe($compte->id);
    expect($audit->association_id)->toBe(TenantContext::currentId());
    expect($audit->user_id)->toBe($user->id);
    expect($audit->motif)->toBe('motif-delettre');
    expect($audit->created_at)->not->toBeNull();

    $ids = json_decode($audit->transaction_ligne_ids, true);
    expect($ids)->toBeArray()->toHaveCount(2);
    expect($ids)->toContain($l1->id)->toContain($l2->id);

    Auth::logout();
});

// ---------------------------------------------------------------------------
// Test 3 : code inexistant → LettrageInexistantException, aucune écriture
// ---------------------------------------------------------------------------
test('delettrer un code inexistant → LettrageInexistantException sans écriture', function () {
    $service = app(LettrageService::class);

    $auditBefore = DB::table('lettrage_audit')->count();

    expect(fn () => $service->delettrer('CODE_INEXISTANT_000'))
        ->toThrow(LettrageInexistantException::class);

    // Aucune ligne d'audit créée
    expect(DB::table('lettrage_audit')->count())->toBe($auditBefore);
});

// ---------------------------------------------------------------------------
// Test 4 : delettrerParLigne délettre TOUT le groupe (pas juste la ligne)
// ---------------------------------------------------------------------------
test('delettrerParLigne délettre toutes les lignes du groupe, pas seulement la ligne passée', function () {
    $compte = makeCompteLettrable('401');
    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    // Groupe de 3 lignes équilibrées : 100 + 50 débit, 150 crédit
    $l1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $l2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '50.00',
        'credit' => '0.00',
        'montant' => 50,
        'sous_categorie_id' => null,
    ]);

    $l3 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '150.00',
        'montant' => 150,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);
    $service->lettrer(collect([$l1, $l2, $l3]));

    // Passer seulement l2 à delettrerParLigne
    $l2Fresh = TransactionLigne::find($l2->id);
    $service->delettrerParLigne($l2Fresh);

    // Les 3 lignes doivent être délettrées
    expect(TransactionLigne::find($l1->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($l2->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($l3->id)->lettrage_code)->toBeNull();
});

// ---------------------------------------------------------------------------
// Test 5 : ligne sans lettrage_code → LigneNonLettreeException
// ---------------------------------------------------------------------------
test('delettrerParLigne sur ligne sans lettrage_code → LigneNonLettreeException', function () {
    $compte = makeCompteLettrable('512');
    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $ligne = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
        // lettrage_code = null (pas de lettrage)
    ]);

    $service = app(LettrageService::class);

    expect(fn () => $service->delettrerParLigne($ligne))
        ->toThrow(LigneNonLettreeException::class);
});

// ---------------------------------------------------------------------------
// Test 6 : combiné — lettrer 3 lignes, délettrer via delettrerParLigne,
//           vérifier l'audit lettre intact + nouvel audit delettre
// ---------------------------------------------------------------------------
test('cas combiné : lettrer 3 lignes puis delettrerParLigne → 3 délettrées + audits lettre et delettre', function () {
    $compte = makeCompteLettrable('4119');
    $tx = Transaction::factory()->create(['association_id' => TenantContext::currentId()]);

    $l1 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '200.00',
        'credit' => '0.00',
        'montant' => 200,
        'sous_categorie_id' => null,
    ]);

    $l2 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '100.00',
        'credit' => '0.00',
        'montant' => 100,
        'sous_categorie_id' => null,
    ]);

    $l3 = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'compte_id' => $compte->id,
        'debit' => '0.00',
        'credit' => '300.00',
        'montant' => 300,
        'sous_categorie_id' => null,
    ]);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$l1, $l2, $l3]));

    // Prendre l1 pour déclencher le délettrage
    $l1Fresh = TransactionLigne::find($l1->id);
    $service->delettrerParLigne($l1Fresh);

    // Les 3 lignes doivent être délettrées
    expect(TransactionLigne::find($l1->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($l2->id)->lettrage_code)->toBeNull();
    expect(TransactionLigne::find($l3->id)->lettrage_code)->toBeNull();

    // Audit action='lettre' doit toujours exister (append-only)
    $auditLettre = DB::table('lettrage_audit')
        ->where('lettrage_code', $code)
        ->where('action', 'lettre')
        ->first();
    expect($auditLettre)->not->toBeNull();

    // Audit action='delettre' doit exister
    $auditDelettre = DB::table('lettrage_audit')
        ->where('lettrage_code', $code)
        ->where('action', 'delettre')
        ->first();
    expect($auditDelettre)->not->toBeNull();

    // Snapshot des IDs délettrés doit contenir les 3 lignes
    $ids = json_decode($auditDelettre->transaction_ligne_ids, true);
    expect($ids)->toBeArray()->toHaveCount(3);
    expect($ids)->toContain($l1->id)->toContain($l2->id)->toContain($l3->id);
});

// ---------------------------------------------------------------------------
// Test 7 : motif optionnel — null si non fourni
// ---------------------------------------------------------------------------
test('delettrer sans motif → colonne motif NULL dans audit', function () {
    $compte = makeCompteLettrable('4112');
    [$l1, $l2] = makePaireEquilibree($compte, 50.00);

    $service = app(LettrageService::class);
    $code = $service->lettrer(collect([$l1, $l2]));

    $service->delettrer($code); // pas de motif

    $audit = DB::table('lettrage_audit')
        ->where('lettrage_code', $code)
        ->where('action', 'delettre')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->motif)->toBeNull();
});
