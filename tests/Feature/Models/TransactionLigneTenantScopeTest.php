<?php

declare(strict_types=1);

/**
 * Scope d'isolation tenant de TransactionLigne (audit #8).
 *
 * transaction_lignes ne porte pas de colonne association_id : la tenancy est
 * dérivée de la transaction parente via TransactionLigneTenantScope (fail-closed).
 * Ces tests prouvent que :
 *   [A] une ligne d'un autre tenant est invisible (find / get / count) ;
 *   [B] un whereIn(...)->update() « nu » ne touche AUCUNE ligne cross-tenant
 *       (le trou concret pointé par l'audit sur LettrageService est fermé) ;
 *   [C] fail-closed : sans TenantContext booté, aucune ligne n'est visible ;
 *   [D] withoutGlobalScopes() reste l'échappatoire explicite (backfill/console).
 */

use App\Models\Association;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Crée une transaction + une ligne dans le tenant donné, puis restaure le tenant
 * précédent. Retourne l'id de la ligne créée.
 */
function creerLigneDansTenant(Association $asso): int
{
    $precedent = TenantContext::current();

    TenantContext::boot($asso);
    $tx = Transaction::factory()->create(['association_id' => $asso->id]);
    $ligneId = TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 100,
        'debit' => 0,
        'credit' => 0,
    ])->id;

    if ($precedent !== null) {
        TenantContext::boot($precedent);
    }

    return $ligneId;
}

test('[A] une ligne d\'un autre tenant est invisible (find / get / count)', function (): void {
    $assoA = TenantContext::current();          // tenant courant (bootstrap global)
    $assoB = Association::factory()->create();

    $ligneAId = creerLigneDansTenant($assoA);
    $ligneBId = creerLigneDansTenant($assoB);

    // On est dans le tenant A : la ligne A est visible, la ligne B ne l'est pas.
    // (NB : la factory Transaction crée aussi ses propres lignes, d'où l'assertion
    // ciblée sur les ids plutôt qu'un count absolu.)
    expect(TransactionLigne::find($ligneBId))->toBeNull();
    expect(TransactionLigne::find($ligneAId))->not->toBeNull();
    expect(TransactionLigne::whereIn('id', [$ligneAId, $ligneBId])->pluck('id')->all())
        ->toBe([$ligneAId]);
    expect(TransactionLigne::pluck('id')->contains($ligneBId))->toBeFalse();
});

test('[B] whereIn()->update() nu ne touche aucune ligne cross-tenant', function (): void {
    $assoB = Association::factory()->create();
    $ligneBId = creerLigneDansTenant($assoB);

    // Depuis le tenant A (courant), une tentative d'update « nue » sur la ligne B
    // (le pattern exact pointé par l'audit sur LettrageService) ne touche rien.
    $affectees = TransactionLigne::whereIn('id', [$ligneBId])
        ->update(['lettrage_code' => 'PWN']);

    expect($affectees)->toBe(0);
    // La ligne B reste intacte (vérifié hors scope).
    expect(TransactionLigne::withoutGlobalScopes()->find($ligneBId)->lettrage_code)->toBeNull();
});

test('[C] fail-closed : sans TenantContext booté, aucune ligne visible', function (): void {
    $assoA = TenantContext::current();
    creerLigneDansTenant($assoA);

    TenantContext::clear();

    expect(TransactionLigne::count())->toBe(0);
    expect(TransactionLigne::get())->toHaveCount(0);
});

test('[D] withoutGlobalScopes reste l\'échappatoire explicite (backfill/console)', function (): void {
    $assoB = Association::factory()->create();
    $ligneBId = creerLigneDansTenant($assoB);

    TenantContext::clear();

    // Le code d'admin/backfill qui DOIT voir toutes les lignes le fait explicitement.
    expect(TransactionLigne::withoutGlobalScopes()->find($ligneBId))->not->toBeNull();
});
