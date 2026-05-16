<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Tests d'intégration réels (vraie génération PDF) pour les routes HTTP
 * RecuPortailController — vérifient les headers et la signature %PDF-.
 *
 * Ces tests n'utilisent pas Storage::fake — ils laissent le service écrire
 * le PDF sur le disk local (storage_path/framework/testing).
 */
beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoEligible(): Association
{
    return Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Martin',
        'signataire_qualite' => 'Président',
    ]);
}

function makeTiersAvecAdresse(Association $asso): Tiers
{
    return Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '12 avenue de la République',
        'code_postal' => '75011',
        'ville' => 'Paris',
    ]);
}

function makeDonLigneReelle(Association $asso, Tiers $tiers, float $montant = 50.0): TransactionLigne
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-03-15',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
        'mode_paiement' => 'virement',
    ]);

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => $montant,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Route recus.fiscal — PDF réel, inline, bytes %PDF-
// ─────────────────────────────────────────────────────────────────────────────
it('GET recus.fiscal génère un vrai PDF : Content-Type, inline, bytes commencent par %PDF-', function () {
    $asso = makeAssoEligible();
    TenantContext::boot($asso);

    $tiers = makeTiersAvecAdresse($asso);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $ligne = makeDonLigneReelle($asso, $tiers, 75.0);

    // Génère le reçu via le vrai service (crée le PDF sur le disk local)
    $recu = app(RecuFiscalService::class)->obtenirOuGenerer($ligne);
    expect($recu->verifierIntegrite())->toBeTrue();

    $url = route('portail.recus.fiscal', ['association' => $asso->slug, 'ligne' => $ligne->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline');
    expect($disposition)->not->toContain('attachment');

    // Anti-régression Acrobat : les bytes doivent commencer par %PDF-
    $body = $response->getContent();
    expect(substr((string) $body, 0, 5))->toBe('%PDF-');
})->skip(fn () => ! class_exists(RecuFiscalService::class), 'Service introuvable');

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Route recus.cotisation — PDF réel, inline, bytes %PDF-
// ─────────────────────────────────────────────────────────────────────────────
it('GET recus.cotisation génère un vrai PDF : Content-Type, inline, bytes commencent par %PDF-', function () {
    $asso = makeAssoEligible();
    TenantContext::boot($asso);

    $tiers = makeTiersAvecAdresse($asso);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Cotisation->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-04-01',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
        'mode_paiement' => 'cheque',
    ]);

    $tx->lignes()->delete();
    $ligneCotisation = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 60.0,
    ]);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $tx->id,
        'deductible_fiscal' => true,
        'montant_facial' => 60.0,
    ]);

    // Génère le reçu via le vrai service
    $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion);
    expect($recu->verifierIntegrite())->toBeTrue();

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $adhesion->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline');
    expect($disposition)->not->toContain('attachment');

    // Anti-régression Acrobat : les bytes doivent commencer par %PDF-
    $body = $response->getContent();
    expect(substr((string) $body, 0, 5))->toBe('%PDF-');
})->skip(fn () => ! class_exists(RecuFiscalService::class), 'Service introuvable');
