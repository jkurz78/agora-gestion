<?php

declare(strict_types=1);

/**
 * Chantier 4 — Gaps miroir statut_reglement.
 *
 * 4 tests RED-then-GREEN pour les sites de mutation du grand livre qui laissaient
 * le miroir statut_reglement périmé en mode partie double.
 *
 * Logique de dérivation chèque :
 *   - T1 recette chèque sans T2 (ligne 411 non lettrée)     → EnAttente
 *   - T1 avec T2 (5112 D, non lettré — en main)             → EnMain
 *   - T1 avec T2 lettrée via T4 (512X D)                    → Recu
 *   - T4 supprimée / source retirée (5112 délettré)         → EnMain
 */

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Enums\TypeLigneFacture;
use App\Models\Compte;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\FactureService;
use App\Services\RemiseBancaireService;
use App\Tenant\TenantContext;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup partagé
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    $this->setupPartieDoubleContext();

    $this->service = app(RemiseBancaireService::class);
    $this->generator = app(EcritureGenerator::class);
    $this->factureSvc = app(FactureService::class);

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
});

// ---------------------------------------------------------------------------
// Helpers locaux
// ---------------------------------------------------------------------------

/**
 * Crée une recette comptant chèque PD et retourne la Transaction T1.
 * T1 porte une ligne 5112 D (non lettrée → EnMain avant remise).
 */
function sdCreerT1Cheque(object $ctx, float $montant = 100.0): Transaction
{
    $compteProduit = Compte::firstOrCreate(
        ['association_id' => TenantContext::currentId(), 'numero_pcg' => '706_sd_'.uniqid()],
        ['intitule' => 'Produit SD', 'classe' => 7, 'lettrable' => false,
            'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false],
    );

    return $ctx->generator->pourRecetteComptant(
        tiers: $ctx->tiers,
        ventilations: [['compte' => $compteProduit, 'montant' => $montant]],
        mode: ModePaiement::Cheque,
        compteTresorerie: $ctx->compte512X,
        date: new DateTimeImmutable('2026-05-20'),
        libelle: 'Recette chèque SD',
    );
}

/**
 * Crée une RemiseBancaire chèque pointant vers le compteBancaire du contexte.
 */
function sdCreerRemise(object $ctx): RemiseBancaire
{
    return $ctx->service->creer([
        'date' => '2026-05-22',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $ctx->compteBancaire->id,
    ]);
}

/**
 * Crée une facture validée avec une T1 chèque (école 411) et retourne [$facture, $t1].
 */
function sdCreerFactureT1(object $ctx): array
{
    $facture = Facture::create([
        'association_id' => $ctx->association->id,
        'date' => '2026-05-20',
        'statut' => StatutFacture::Brouillon,
        'tiers_id' => $ctx->tiers->id,
        'saisi_par' => $ctx->user->id,
        'exercice' => 2025,
        'montant_total' => 0,
        'mode_paiement_prevu' => ModePaiement::Cheque->value,
        'compte_bancaire_id' => null,
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'sous_categorie_id' => $ctx->sc706->id,
        'libelle' => 'Cotisation test SD',
        'montant' => 150.00,
        'ordre' => 1,
    ]);

    $ctx->factureSvc->valider($facture);
    $facture->refresh();

    $t1 = $facture->transactions()->first();

    return [$facture, $t1];
}

// ---------------------------------------------------------------------------
// Gap C1 — modifier() : source retirée doit dériver EnMain, pas rester EnAttente
// ---------------------------------------------------------------------------

it('[C1] modifier() source retirée → statut_reglement dérivé EnMain (chèque en main)', function (): void {
    $remise = sdCreerRemise($this);

    $tx1 = sdCreerT1Cheque($this, 60.0);
    $tx2 = sdCreerT1Cheque($this, 40.0);

    // Comptabiliser les 2 sources → T4 créée, les deux → Recu
    $this->service->comptabiliser($remise, [$tx1->id, $tx2->id]);

    $tx1->refresh();
    $tx2->refresh();
    expect($tx1->statut_reglement)->toBe(StatutReglement::Recu);
    expect($tx2->statut_reglement)->toBe(StatutReglement::Recu);

    // Modifier : retirer tx2 du périmètre
    $this->service->modifier($remise->fresh(), [$tx1->id]);

    $tx2->refresh();

    // tx2 a été retirée : sa ligne 5112 est délettrée (T4 supprimée et recréée sans elle).
    // En mode PD, le syncer doit dériver EnMain (chèque revenu en main, non remisé).
    // Sans le syncer, la valeur serait EnAttente (legacy fallback de modifier).
    expect($tx2->statut_reglement)->toBe(
        StatutReglement::EnMain,
        'tx2 retirée de la remise : chèque en main → EnMain (pas EnAttente)'
    );

    // tx1 conservée → toujours Recu (T4 recréée avec elle)
    $tx1->refresh();
    expect($tx1->statut_reglement)->toBe(StatutReglement::Recu);
});

// ---------------------------------------------------------------------------
// Gap C2 — supprimer() : source doit dériver EnMain, pas EnAttente
// ---------------------------------------------------------------------------

it('[C2] supprimer() remise → statut_reglement dérivé EnMain (chèque retour en main)', function (): void {
    $remise = sdCreerRemise($this);

    $tx = sdCreerT1Cheque($this, 80.0);

    $this->service->comptabiliser($remise, [$tx->id]);

    $tx->refresh();
    expect($tx->statut_reglement)->toBe(StatutReglement::Recu);

    // Supprimer la remise → T4 supprimée, ligne 5112 délettrée
    $this->service->supprimer($remise->fresh());

    $tx->refresh();

    // En mode PD, le syncer doit dériver EnMain (5112 non lettré = chèque en main).
    // Sans le syncer, la valeur serait EnAttente (legacy fallback de supprimer).
    expect($tx->statut_reglement)->toBe(
        StatutReglement::EnMain,
        'chèque retour en main après suppression remise → EnMain (pas EnAttente)'
    );
    expect($tx->remise_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Gap C3 — FactureService::marquerReglementRecu() chèque → EnMain (pas Recu)
// ---------------------------------------------------------------------------

it('[C3] marquerReglementRecu() chèque → statut_reglement dérivé EnMain (5112 non remisé)', function (): void {
    [$facture, $t1] = sdCreerFactureT1($this);

    // Avant encaissement : T1 porte une ligne 411 D (non lettrée) → EnAttente
    $t1->refresh();
    expect($t1->statut_reglement)->toBe(StatutReglement::EnAttente);

    // Encaisser : génère T2 (5112 D / 411 C) + lettrage 411
    $this->factureSvc->marquerReglementRecu($facture, [$t1->id]);

    $t1->refresh();

    // En mode PD, le syncer doit dériver EnMain (5112 non lettré = chèque reçu, non remisé).
    // Sans le syncer, la valeur serait Recu (legacy fallback de marquerReglementRecu).
    expect($t1->statut_reglement)->toBe(
        StatutReglement::EnMain,
        'chèque encaissé mais non remisé → EnMain (pas Recu)'
    );
});

// ---------------------------------------------------------------------------
// Gap I5 — enregistrerBrouillon() chèque → EnMain (pas Recu)
// ---------------------------------------------------------------------------

it('[I5] enregistrerBrouillon() chèque → statut_reglement dérivé EnMain (5112 non lettré)', function (): void {
    $remise = sdCreerRemise($this);

    $tx = sdCreerT1Cheque($this, 70.0);

    // Avant brouillon : EnAttente (DB default — EcritureGenerator::pourRecetteComptant
    // ne passe pas par TransactionService, donc le syncer n'est pas appelé à la création).
    // La valeur persistée en base n'est pas encore dérivée : c'est précisément ce que ce
    // test vérifie — enregistrerBrouillon doit syncer APRÈS avoir posé Recu en legacy.
    $tx->refresh();
    expect($tx->statut_reglement)->toBe(StatutReglement::EnAttente);

    // Enregistrer le brouillon : place tx dans la remise mais pas de T4 encore
    $this->service->enregistrerBrouillon($remise, [$tx->id]);

    $tx->refresh();

    // En mode PD, le syncer doit dériver EnMain (5112 toujours non lettré — pas de T4).
    // Sans le syncer, la valeur serait Recu (legacy fallback de enregistrerBrouillon).
    expect($tx->statut_reglement)->toBe(
        StatutReglement::EnMain,
        'brouillon remise chèque : 5112 non lattré → EnMain (pas Recu)'
    );
    // remise_id bien posé
    expect($tx->remise_id)->toBe($remise->id);
});
