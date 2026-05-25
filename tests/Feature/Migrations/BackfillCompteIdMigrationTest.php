<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\Compta\Migrations\CompteIdBackfiller;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * Step 36 of plans/fondations-partie-double-slice1.md (sous-slice 1d).
 *
 * Tests for the backfill migration that populates transaction_lignes.compte_id
 * from sous_categorie_id via the mapping sous_categories.code_cerfa → comptes.numero_pcg.
 *
 * Tests [A], [B], [C], [D] per spec.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::firstOrFail();
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Insère un compte PCG directement si il n'existe pas pour ce tenant.
 */
function insertCompteIfMissing(int $associationId, string $numeroPcg, string $intitule): int
{
    $existing = DB::table('comptes')
        ->where('association_id', $associationId)
        ->where('numero_pcg', $numeroPcg)
        ->value('id');

    if ($existing !== null) {
        return (int) $existing;
    }

    return (int) DB::table('comptes')->insertGetId([
        'association_id' => $associationId,
        'numero_pcg' => $numeroPcg,
        'intitule' => $intitule,
        'classe' => (int) $numeroPcg[0],
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
        'lettrable' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Test [A]: lignes avec sous_categorie_id + compte correspondant → compte_id peuplé.
 */
it('[A] remplit compte_id depuis sous_categorie_id → code_cerfa → numero_pcg', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);

    $sousCategorie = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'code_cerfa' => '6061',
    ]);

    $compteId = insertCompteIfMissing($this->association->id, '6061', 'Fournitures de bureau');

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '50.00',
        'compte_id' => null,
    ]);

    // Avant backfill : compte_id est null
    $avant = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect($avant)->toBeNull();

    // Exécuter le backfill via le helper officiel
    $nbAffected = CompteIdBackfiller::up();
    expect($nbAffected)->toBeGreaterThanOrEqual(1);

    // Après backfill : compte_id est peuplé
    $apres = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect((int) $apres)->toBe($compteId);
});

/**
 * Test [B]: lignes orphelines (SC sans code_cerfa ou sans compte correspondant)
 * → compte_id reste null, pas d'erreur fatale.
 */
it('[B] laisse compte_id à null pour les lignes orphelines sans correspondance', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);

    // Cas 1 : sous-catégorie sans code_cerfa
    $scSansCode = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'code_cerfa' => null,
    ]);

    // Cas 2 : sous-catégorie avec code_cerfa inexistant dans comptes
    $scSansCompte = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'code_cerfa' => '9999',
    ]);

    // S'assurer que 9999 n'existe pas dans comptes pour ce tenant
    DB::table('comptes')
        ->where('association_id', $this->association->id)
        ->where('numero_pcg', '9999')
        ->delete();

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneOrpheline1 = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $scSansCode->id,
        'montant' => '15.00',
        'compte_id' => null,
    ]);

    $ligneOrpheline2 = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $scSansCompte->id,
        'montant' => '15.00',
        'compte_id' => null,
    ]);

    // Le backfill ne doit pas lever d'exception
    expect(fn () => CompteIdBackfiller::up())->not->toThrow(\Throwable::class);

    // Les lignes orphelines restent à null
    $apres1 = DB::table('transaction_lignes')->where('id', $ligneOrpheline1)->value('compte_id');
    $apres2 = DB::table('transaction_lignes')->where('id', $ligneOrpheline2)->value('compte_id');

    expect($apres1)->toBeNull();
    expect($apres2)->toBeNull();
});

/**
 * Test [C]: idempotence — rollback puis re-apply ne casse rien.
 */
it('[C] est idempotente — rollback puis re-apply fonctionne sans erreur', function () {
    $categorie = Categorie::factory()->create(['association_id' => $this->association->id]);

    $sousCategorie = SousCategorie::factory()->create([
        'association_id' => $this->association->id,
        'categorie_id' => $categorie->id,
        'code_cerfa' => '6062',
    ]);

    $compteId = insertCompteIfMissing($this->association->id, '6062', 'Petit outillage');

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => '100.00',
        'compte_id' => null,
    ]);

    // Première application
    CompteIdBackfiller::up();
    $apres1 = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect((int) $apres1)->toBe($compteId);

    // Rollback (down)
    CompteIdBackfiller::down();
    $apresRollback = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect($apresRollback)->toBeNull();

    // Re-apply (idempotent)
    CompteIdBackfiller::up();
    $apres2 = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect((int) $apres2)->toBe($compteId);

    // Double apply est un no-op (WHERE compte_id IS NULL filtre les lignes déjà backfillées)
    $nbAffected = CompteIdBackfiller::up();
    expect($nbAffected)->toBe(0);

    $apres3 = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect((int) $apres3)->toBe($compteId);
});

/**
 * Test [D]: les lignes qui ont déjà un compte_id ne sont pas touchées.
 */
it('[D] ne modifie pas les lignes qui ont déjà un compte_id', function () {
    $compteExistant = DB::table('comptes')
        ->where('association_id', $this->association->id)
        ->whereNotNull('numero_pcg')
        ->first();

    expect($compteExistant)->not->toBeNull();

    $transaction = Transaction::factory()->create([
        'association_id' => $this->association->id,
    ]);

    // Ligne avec compte_id déjà rempli (pas de sous_categorie_id)
    $ligneId = DB::table('transaction_lignes')->insertGetId([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => null,
        'montant' => '75.00',
        'compte_id' => $compteExistant->id,
    ]);

    $nbAffected = CompteIdBackfiller::up();
    // Cette ligne ne doit pas être touchée
    expect($nbAffected)->toBe(0);

    $apres = DB::table('transaction_lignes')->where('id', $ligneId)->value('compte_id');
    expect((int) $apres)->toBe((int) $compteExistant->id);
});
