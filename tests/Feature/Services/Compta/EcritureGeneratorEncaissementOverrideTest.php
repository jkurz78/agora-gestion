<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use Tests\Support\CreatesPartieDoubleContext;

uses(CreatesPartieDoubleContext::class);

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->setupPartieDoubleContext();

    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);

    $this->generator = app(EcritureGenerator::class);

    $this->compte5112 = Compte::where('numero_pcg', '5112')
        ->where('association_id', $this->association->id)
        ->firstOrFail();
});

// ---------------------------------------------------------------------------
// Helper : crée T1 créance via pourRecetteACredit
// ---------------------------------------------------------------------------

function creerCreanceOverride(EcritureGenerator $generator, Tiers $tiers, Compte $compte706, float $montant = 120.00): Transaction
{
    return $generator->pourRecetteACredit(
        tiers: $tiers,
        ventilations: [['compte' => $compte706, 'montant' => $montant]],
        dateConstatation: new DateTimeImmutable('2026-05-20'),
        libelle: 'Facture test override',
    );
}

// ---------------------------------------------------------------------------
// Cas 1 : override → portage sur 512X (pas 5112)
// ---------------------------------------------------------------------------

test('pourEncaissementCreance avec comptePortageOverride utilise le compte fourni au lieu de 5112', function () {
    $t1 = creerCreanceOverride($this->generator, $this->tiers, $this->compte706);

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $t2 = $this->generator->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement chèque override',
        comptePortageOverride: $this->compte512X,
    );

    expect($t2)->toBeInstanceOf(Transaction::class);
    expect($t2->lignes)->toHaveCount(2);

    // La ligne de portage doit être sur 512X, PAS sur 5112
    $lignePortage = $t2->lignes->firstWhere('compte_id', $this->compte512X->id);
    expect($lignePortage)->not->toBeNull('La ligne portage doit être sur compte512X');
    expect((float) $lignePortage->debit)->toBe(120.00);
    expect((float) $lignePortage->credit)->toBe(0.00);
    expect($lignePortage->tiers_id)->toBeNull('Ligne portage ne porte pas de tiers — FEC-conformité');

    // Aucune ligne sur 5112
    $ligne5112 = $t2->lignes->firstWhere('compte_id', $this->compte5112->id);
    expect($ligne5112)->toBeNull('5112 ne doit PAS apparaître quand override est fourni');

    // Ligne 411 C avec tiers
    $ligne411 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411)->not->toBeNull('La ligne 411 doit exister dans T2');
    expect((float) $ligne411->credit)->toBe(120.00);
    expect((int) $ligne411->tiers_id)->toBe((int) $this->tiers->id);
});

// ---------------------------------------------------------------------------
// Cas 2 : lettrage 411 inter-tx correct avec override
// ---------------------------------------------------------------------------

test('pourEncaissementCreance avec comptePortageOverride lettre correctement 411 T1↔T2', function () {
    $t1 = creerCreanceOverride($this->generator, $this->tiers, $this->compte706);

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $ligne411T1 = $t1->lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411T1)->not->toBeNull();

    $t2 = $this->generator->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        comptePortageOverride: $this->compte512X,
    );

    $ligne411T2 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411T2)->not->toBeNull();

    $ligne411T1Fresh = $ligne411T1->fresh();
    $ligne411T2Fresh = $ligne411T2->fresh();

    expect($ligne411T1Fresh->lettrage_code)->not->toBeNull('T1 411 doit être lettrée');
    expect($ligne411T2Fresh->lettrage_code)->not->toBeNull('T2 411 doit être lettrée');
    expect($ligne411T1Fresh->lettrage_code)->toBe(
        $ligne411T2Fresh->lettrage_code,
        'T1 et T2 411 doivent partager le même code de lettrage'
    );
});

// ---------------------------------------------------------------------------
// Cas 3 : sans override, chèque → portage sur 5112 (comportement par défaut inchangé)
// ---------------------------------------------------------------------------

test('pourEncaissementCreance sans override chèque utilise 5112 par défaut', function () {
    $t1 = creerCreanceOverride($this->generator, $this->tiers, $this->compte706, 80.00);

    $compte411 = Compte::where('numero_pcg', '411')
        ->where('association_id', $this->association->id)
        ->firstOrFail();

    $t2 = $this->generator->pourEncaissementCreance(
        transactionCreance: $t1,
        mode: ModePaiement::Cheque,
        compteTresorerie: $this->compte512X,
        datePaiement: new DateTimeImmutable('2026-05-25'),
        libelle: 'Encaissement chèque défaut',
        // comptePortageOverride absent → null implicite
    );

    expect($t2)->toBeInstanceOf(Transaction::class);

    // La ligne de portage doit être sur 5112 (comportement par défaut)
    $ligne5112 = $t2->lignes->firstWhere('compte_id', $this->compte5112->id);
    expect($ligne5112)->not->toBeNull('Sans override, chèque doit utiliser 5112');
    expect((float) $ligne5112->debit)->toBe(80.00);

    // Pas de ligne sur 512X pour le portage
    $lignePortage512X = $t2->lignes->firstWhere('compte_id', $this->compte512X->id);
    expect($lignePortage512X)->toBeNull('512X ne doit PAS être utilisé sans override pour chèque');

    // Ligne 411 C présente
    $ligne411 = $t2->lignes->firstWhere('compte_id', $compte411->id);
    expect($ligne411)->not->toBeNull();
    expect((float) $ligne411->credit)->toBe(80.00);
});
