<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Helpers locaux — réutilisent le pattern des RemiseBancaireServicePartieDoubleTest
// ---------------------------------------------------------------------------

/**
 * Crée une T1 recette comptant chèque via EcritureGenerator et retourne la transaction.
 */
function mdrd_creerTxCheque(object $ctx, float $montant): Transaction
{
    $tiers = Tiers::factory()->create(['association_id' => TenantContext::currentId()]);
    $compteProduit = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '706_mdrd_'.uniqid()],
        [
            'intitule' => 'Produit test découplage',
            'classe' => 7,
            'lettrable' => false,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
        ]
    );

    return $ctx->generator->pourRecetteComptant(
        tiers: $tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette chèque découplage test',
    );
}

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();
    $this->service = app(RemiseBancaireService::class);
    $this->generator = app(EcritureGenerator::class);
});

// ---------------------------------------------------------------------------
// Scénario "découplage" :
// Sources avec reference = NULL après forçage manuel → modifier() ne doit PAS
// confondre les sources avec la T4 (critère structurel 512X, pas reference IS NULL).
// ---------------------------------------------------------------------------

it('[découplage] modifier() identifie la T4 par ligne 512X même quand les sources ont reference = NULL', function () {
    // 1. Créer une remise et 3 sources PD (avec lignes 5112)
    $remise = $this->service->creer([
        'date' => '2026-05-22',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteBancaire->id,
    ]);

    $tx1 = mdrd_creerTxCheque($this, 50.00);
    $tx2 = mdrd_creerTxCheque($this, 30.00);
    $tx3 = mdrd_creerTxCheque($this, 20.00);

    // 2. Comptabiliser (assigne references RBC-xxxxx-001/002/003 + crée T4)
    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id, $tx3->id]);

    // Vérifier que la T4 existe et identifier son ID via critère structurel
    $sourceIds = [$tx1->id, $tx2->id, $tx3->id];
    $compte512X = $this->compte512X;
    $t4avant = Transaction::where('remise_id', $remise->id)
        ->whereHas('lignes', fn ($q) => $q->where('compte_id', $compte512X->id)->where('debit', '>', 0))
        ->firstOrFail();
    $t4avantId = (int) $t4avant->id;

    // La T4 ne doit PAS être parmi les sources
    expect($t4avantId)->not->toBeIn($sourceIds, 'T4 ne doit pas être parmi les sources');

    // 3. Forcer reference = NULL sur TOUTES les sources (pas la T4)
    //    Simule des chèques remisés réels en prod qui ont reference = NULL (Finding 2)
    DB::table('transactions')
        ->whereIn('id', $sourceIds)
        ->update(['reference' => null]);

    // Vérifier que la T4 a bien reference = NULL aussi (invariant : elle n'a jamais eu de reference)
    $t4avant->refresh();
    expect($t4avant->reference)->toBeNull('La T4 a toujours reference = NULL');

    // Après le forçage, sources ET T4 ont reference = NULL → l'ancien critère reference IS NULL
    // ne peut plus distinguer les sources de la T4.
    // Vérifier que 4 transactions ont désormais reference = NULL (3 sources + T4)
    $nbNullReference = Transaction::where('remise_id', $remise->id)
        ->whereNull('reference')
        ->count();
    expect($nbNullReference)->toBe(4, '3 sources + 1 T4 ont toutes reference = NULL après forçage');

    // 4. Appeler modifier() en retirant tx3 (on garde tx1 + tx2)
    //    Avec l'ancien code (whereNotNull('reference') / whereNotIn reference), cette opération
    //    ne verrait aucune "source à retirer" (toutes ont reference = NULL comme la T4)
    //    et risquait de mal compter / mal attribuer les index.
    $this->service->modifier($remise->fresh(), [$tx1->id, $tx2->id]);

    // 5. Assertions — la T4 doit toujours exister (identifiée par ligne 512X)
    $t4apres = Transaction::where('remise_id', $remise->id)
        ->whereHas('lignes', fn ($q) => $q->where('compte_id', $compte512X->id)->where('debit', '>', 0))
        ->first();

    expect($t4apres)->not->toBeNull('La T4 doit exister après modifier(), identifiée par ligne 512X');

    // L'ancienne T4 a été détruite et une nouvelle recréée (id peut avoir changé)
    // Le montant de la T4 = somme des sources gardées (50 + 30 = 80)
    $ligneT4_512X = TransactionLigne::where('transaction_id', $t4apres->id)
        ->where('compte_id', $compte512X->id)
        ->where('debit', '>', 0)
        ->first();
    expect($ligneT4_512X)->not->toBeNull('T4 doit avoir une ligne 512X D');
    expect((float) $ligneT4_512X->debit)->toBe(80.00, 'T4 montant = somme des sources gardées (50+30)');

    // 6. La source retirée (tx3) doit être détachée — en mode PD, le syncer dérive EnMain
    //    (5112 délettré = chèque en main, non remisé). Legacy fallback était EnAttente.
    $tx3->refresh();
    expect($tx3->remise_id)->toBeNull('tx3 doit être détachée de la remise');
    expect($tx3->statut_reglement)->toBe(StatutReglement::EnMain, 'tx3 retirée : chèque en main → EnMain (mode PD)');

    // 7. Les sources gardées (tx1, tx2) sont toujours liées à la remise
    $tx1->refresh();
    $tx2->refresh();
    expect((int) $tx1->remise_id)->toBe((int) $remise->id, 'tx1 doit rester liée à la remise');
    expect((int) $tx2->remise_id)->toBe((int) $remise->id, 'tx2 doit rester liée à la remise');
    expect($tx1->statut_reglement)->toBe(StatutReglement::Recu);
    expect($tx2->statut_reglement)->toBe(StatutReglement::Recu);

    // 8. La T4 ne doit PAS être parmi les sources (invariant fondamental)
    $t4apresId = (int) $t4apres->id;
    expect($t4apresId)->not->toBe((int) $tx1->id, 'T4 ne doit pas être tx1');
    expect($t4apresId)->not->toBe((int) $tx2->id, 'T4 ne doit pas être tx2');
    expect($t4apresId)->not->toBe((int) $tx3->id, 'T4 ne doit pas être tx3');
});
