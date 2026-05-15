<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Tests d'intégration HTTP pour RecuPortailController.
 *
 * Couvre :
 *   - Security : ownership intra-asso (403)
 *   - Security : cross-tenant via Adhesion TenantScope (404 sur binding)
 *   - Security : cross-tenant pour TransactionLigne (403 via service)
 *   - Integration : Content-Type=application/pdf, Content-Disposition=inline, bytes %PDF-
 *   - Mono : routes portail.mono.recus.*
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoEligibleCtrl(): Association
{
    return Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Martin',
        'signataire_qualite' => 'Président',
    ]);
}

function makeTiersCompletCtrl(Association $asso): Tiers
{
    return Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '12 avenue de la République',
        'code_postal' => '75011',
        'ville' => 'Paris',
    ]);
}

function makeFakePdfRecu(Association $asso, Tiers $tiers, TransactionLigne $ligne): RecuFiscalEmis
{
    $fakePdf = '%PDF-1.4 fake content for test';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdf);

    return RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdf),
    ]);
}

function makeAdhesionAvecRecu(Association $asso, Tiers $tiers): array
{
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
    $ligne = TransactionLigne::factory()->create([
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

    $recu = makeFakePdfRecu($asso, $tiers, $ligne);

    return [$adhesion, $recu, $ligne];
}

function makeDonLigneAvecRecu(Association $asso, Tiers $tiers): array
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-06-01',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
    ]);

    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100.0,
    ]);

    $recu = makeFakePdfRecu($asso, $tiers, $ligne);

    return [$ligne, $recu];
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : cotisation — PDF servi inline avec bons headers
// ─────────────────────────────────────────────────────────────────────────────
it('GET recus.cotisation retourne PDF inline avec Content-Type application/pdf et bytes %PDF-', function () {
    $asso = makeAssoEligibleCtrl();
    TenantContext::boot($asso);

    $tiers = makeTiersCompletCtrl($asso);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    [$adhesion, $recu] = makeAdhesionAvecRecu($asso, $tiers);

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $adhesion->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline');
    expect($disposition)->not->toContain('attachment');

    $body = $response->getContent();
    expect(substr((string) $body, 0, 4))->toBe('%PDF');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : fiscal don — PDF servi inline avec bons headers
// ─────────────────────────────────────────────────────────────────────────────
it('GET recus.fiscal retourne PDF inline avec Content-Type application/pdf et bytes %PDF-', function () {
    $asso = makeAssoEligibleCtrl();
    TenantContext::boot($asso);

    $tiers = makeTiersCompletCtrl($asso);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    [$ligne, $recu] = makeDonLigneAvecRecu($asso, $tiers);

    $url = route('portail.recus.fiscal', ['association' => $asso->slug, 'ligne' => $ligne->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('inline');
    expect($disposition)->not->toContain('attachment');

    $body = $response->getContent();
    expect(substr((string) $body, 0, 4))->toBe('%PDF');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Unauthenticated → redirect
// ─────────────────────────────────────────────────────────────────────────────
it('non authentifié sur recus.cotisation → redirect', function () {
    $asso = Association::factory()->create(['slug' => 'test-asso']);
    TenantContext::boot($asso);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => Tiers::factory()->create(['association_id' => $asso->id])->id,
    ]);

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $adhesion->id]);
    $this->get($url)->assertRedirect();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Intrusion intra-asso cotisation — Alice 403 sur adhésion de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 GET recus.cotisation avec l\'adhésion de Bob', function () {
    $asso = makeAssoEligibleCtrl();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = makeTiersCompletCtrl($asso);

    [$bobAdhesion] = makeAdhesionAvecRecu($asso, $bob);

    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $bobAdhesion->id]);
    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Cross-tenant cotisation — Adhesion TenantScope → 404
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cross-tenant cotisation — TenantScope retourne 404 pour adhésion asso B', function () {
    $assoA = makeAssoEligibleCtrl();
    $assoB = makeAssoEligibleCtrl();

    TenantContext::boot($assoB);
    $tiersB = makeTiersCompletCtrl($assoB);
    [$adhesionB] = makeAdhesionAvecRecu($assoB, $tiersB);

    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    // On tente d'accéder à l'adhésion B depuis le slug de A
    $url = route('portail.recus.cotisation', ['association' => $assoA->slug, 'adhesion' => $adhesionB->id]);
    // TenantScope filtre → Adhesion introuvable → 404
    $this->get($url)->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Intrusion intra-asso don fiscal — Alice 403 sur ligne de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 GET recus.fiscal avec la ligne de Bob', function () {
    $asso = makeAssoEligibleCtrl();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = makeTiersCompletCtrl($asso);

    [$bobLigne] = makeDonLigneAvecRecu($asso, $bob);

    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    $url = route('portail.recus.fiscal', ['association' => $asso->slug, 'ligne' => $bobLigne->id]);
    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Cross-tenant don fiscal — ligne asso B invisible depuis asso A → 403
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cross-tenant don fiscal — ligne asso B retourne 403 depuis asso A', function () {
    $assoA = makeAssoEligibleCtrl();
    $assoB = makeAssoEligibleCtrl();

    TenantContext::boot($assoB);
    $tiersB = makeTiersCompletCtrl($assoB);
    [$ligneB] = makeDonLigneAvecRecu($assoB, $tiersB);

    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    $url = route('portail.recus.fiscal', ['association' => $assoA->slug, 'ligne' => $ligneB->id]);
    // TiersDonsTimelineService ne retourne pas ligneB pour alice/assoA → 403
    $this->get($url)->assertForbidden();
});

// Note: Les tests mono (portail.mono.recus.*) sont dans MonoMesAdhesionsEtDonsTest.php
// où le cleanup DB::table('association')->delete() garantit l'isolation mono.
