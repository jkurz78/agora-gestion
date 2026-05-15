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
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
    DB::table('association')->delete();
    Storage::fake('local');
});

afterEach(function () {
    MonoAssociation::flush();
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mes adhésions accessible en mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/mes-adhesions retourne 200 avec Mes adhésions', function () {
    $asso = Association::factory()->create(['slug' => 'svs']);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'exercice' => 2025,
    ]);

    $this->get('/portail/mes-adhesions')
        ->assertStatus(200)
        ->assertSeeText('Mes adhésions');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Mes dons accessible en mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/mes-dons retourne 200 avec Mes dons', function () {
    $asso = Association::factory()->create([
        'slug' => 'svs',
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue du test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-03-01',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
    ]);

    $this->get('/portail/mes-dons')
        ->assertStatus(200)
        ->assertSeeText('Mes dons');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Téléchargement reçu cotisation via route HTTP mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/recus/cotisation/{id} sert le PDF inline', function () {
    $asso = Association::factory()->create([
        'slug' => 'svs',
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
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
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 50.00,
    ]);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $tx->id,
        'deductible_fiscal' => true,
        'montant_facial' => 50.00,
    ]);

    // Fake PDF dans storage
    $fakePdf = '%PDF-1.4 mono test cotisation';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdf);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdf),
    ]);

    app()->bind(\App\Services\RecuFiscalService::class, fn () => new class($recu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, mixed $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }
    });

    $url = route('portail.mono.recus.cotisation', ['adhesion' => $adhesion->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Téléchargement reçu fiscal via route HTTP mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/recus/fiscal/{id} sert le PDF inline', function () {
    $asso = Association::factory()->create([
        'slug' => 'svs',
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => '2025-05-01',
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
    ]);
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 75.00,
    ]);

    // Fake PDF dans storage
    $fakePdf = '%PDF-1.4 mono test don';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdf);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdf),
    ]);

    app()->bind(\App\Services\RecuFiscalService::class, fn () => new class($recu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenerer(TransactionLigne $l, mixed $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }
    });

    $url = route('portail.mono.recus.fiscal', ['ligne' => $ligne->id]);
    $response = $this->get($url);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});
