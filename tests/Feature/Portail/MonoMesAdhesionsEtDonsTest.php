<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Portail\MesAdhesions;
use App\Livewire\Portail\MesDons;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Support\MonoAssociation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
// Test 3 : Téléchargement reçu cotisation depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: telechargerRecuCotisation depuis MesAdhesions appelle le service sans erreur', function () {
    $asso = Association::factory()->create(['slug' => 'svs', 'eligible_recu_fiscal' => true]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $transaction = Transaction::factory()->create([
        'statut_reglement' => StatutReglement::Recu,
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $transaction->id,
        'deductible_fiscal' => true,
        'montant_facial' => 50.00,
    ]);

    $fakeRecu = RecuFiscalEmis::factory()->make(['id' => 999, 'association_id' => $asso->id]);
    $countBefore = RecuFiscalEmis::count();

    app()->bind(RecuFiscalService::class, fn () => new class($fakeRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $fakeRecu) {}

        public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, mixed $user = null): RecuFiscalEmis
        {
            return $this->fakeRecu;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
        }

        public function streamDownloadResponse(RecuFiscalEmis $recu): StreamedResponse
        {
            return response()->streamDownload(fn () => print '%PDF-fake', 'recu.pdf', ['Content-Type' => 'application/pdf']);
        }
    });

    Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $adhesion->id)
        ->assertOk();

    expect(RecuFiscalEmis::count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Téléchargement reçu fiscal depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: telechargerRecuFiscal depuis MesDons appelle le service sans erreur', function () {
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

    $fakeRecu = RecuFiscalEmis::factory()->make(['id' => 999, 'association_id' => $asso->id]);
    $countBefore = RecuFiscalEmis::count();

    app()->bind(RecuFiscalService::class, fn () => new class($fakeRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $fakeRecu) {}

        public function obtenirOuGenerer(TransactionLigne $ligne, mixed $user = null): RecuFiscalEmis
        {
            return $this->fakeRecu;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
        }

        public function streamDownloadResponse(RecuFiscalEmis $recu): StreamedResponse
        {
            return response()->streamDownload(fn () => print '%PDF-fake', 'recu.pdf', ['Content-Type' => 'application/pdf']);
        }
    });

    Livewire::test(MesDons::class, ['association' => $asso])
        ->call('telechargerRecuFiscal', $ligne->id)
        ->assertOk();

    expect(RecuFiscalEmis::count())->toBe($countBefore);
});
