<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Livewire\Portail\MesDons;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Helper : crée une SousCategorie avec usage Don dans le contexte d'une asso.
 */
function makeSousCatDon(Association $asso): SousCategorie
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    return $sousCat;
}

/**
 * Helper : crée une transaction Recette + ligne Don pour un Tiers.
 */
function makeDonLigne(Association $asso, Tiers $tiers, SousCategorie $sousCat, string $date, float $montant, StatutReglement $statut = StatutReglement::Recu): TransactionLigne
{
    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => $date,
        'statut_reglement' => $statut,
        'type' => TypeTransaction::Recette->value,
    ]);

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => $montant,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Regroupement par année + total
// ─────────────────────────────────────────────────────────────────────────────
it('regroupe les dons par année civile desc avec total correct par année', function () {
    $asso = Association::factory()->create([
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
        'eligible_recu_fiscal' => true,
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue du test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $sousCat = makeSousCatDon($asso);

    // 2 dons en 2025 (50 + 30 = 80)
    makeDonLigne($asso, $tiers, $sousCat, '2025-03-15', 50.0);
    makeDonLigne($asso, $tiers, $sousCat, '2025-09-20', 30.0);
    // 1 don en 2024 (100)
    makeDonLigne($asso, $tiers, $sousCat, '2024-06-01', 100.0);
    // 1 don en 2023 (25)
    makeDonLigne($asso, $tiers, $sousCat, '2023-11-11', 25.0);

    $html = Livewire::test(MesDons::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    // 3 sections d'années
    expect($html)
        ->toContain('2025')
        ->toContain('2024')
        ->toContain('2023');

    // 2025 apparaît avant 2024 (tri desc)
    expect(strpos($html, '2025'))->toBeLessThan(strpos($html, '2024'));
    expect(strpos($html, '2024'))->toBeLessThan(strpos($html, '2023'));

    // Totaux présents
    expect($html)->toContain('80.00');
    expect($html)->toContain('100.00');
    expect($html)->toContain('25.00');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : CTA Nouveau don — URL spécifique
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le CTA Nouveau don avec url_nouveau_don quand défini', function () {
    $asso = Association::factory()->create([
        'url_nouveau_don' => 'https://hello.asso/mon-don',
        'url_site_web' => 'https://monasso.fr',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesDons::class, ['association' => $asso])->html();

    expect($html)
        ->toContain('https://hello.asso/mon-don')
        ->toContain('Faire un nouveau don');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : CTA Nouveau don — fallback site web
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le CTA avec url_site_web si url_nouveau_don est null', function () {
    $asso = Association::factory()->create([
        'url_nouveau_don' => null,
        'url_site_web' => 'https://monasso.fr',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesDons::class, ['association' => $asso])->html();

    expect($html)
        ->toContain('https://monasso.fr')
        ->toContain('Faire un nouveau don');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : CTA Nouveau don — caché si les 2 URLs null
// ─────────────────────────────────────────────────────────────────────────────
it('cache le CTA Nouveau don si url_nouveau_don et url_site_web sont null', function () {
    $asso = Association::factory()->create([
        'url_nouveau_don' => null,
        'url_site_web' => null,
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $html = Livewire::test(MesDons::class, ['association' => $asso])->html();

    expect($html)->not->toContain('Faire un nouveau don');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Bouton reçu — déjà émis (stream PDF, pas de nouveau reçu créé)
// ─────────────────────────────────────────────────────────────────────────────
it('bouton reçu visible si reçu déjà émis et clic stream le PDF sans créer un nouveau reçu', function () {
    $asso = Association::factory()->create([
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

    $sousCat = makeSousCatDon($asso);
    $ligne = makeDonLigne($asso, $tiers, $sousCat, '2025-06-01', 100.0);

    // Créer un reçu pré-existant
    $fakePdfContent = '%PDF-1.4 fake';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdfContent);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdfContent),
    ]);

    // Le bouton doit être visible
    $html = Livewire::test(MesDons::class, ['association' => $asso])->html();
    expect($html)->toContain('Télécharger le reçu');

    $countBefore = RecuFiscalEmis::count();

    $capturedRecu = $recu;
    app()->bind(RecuFiscalService::class, fn () => new class($capturedRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenerer(TransactionLigne $ligne, mixed $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
        }

        public function streamDownloadResponse(RecuFiscalEmis $recu): StreamedResponse
        {
            return response()->streamDownload(fn () => print '%PDF-fake', 'recu.pdf', ['Content-Type' => 'application/pdf']);
        }
    });

    Livewire::test(MesDons::class, ['association' => $asso])
        ->call('telechargerRecuFiscal', $ligne->id);

    // Pas de nouveau reçu créé
    expect(RecuFiscalEmis::count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Bouton reçu — génération à la demande
// ─────────────────────────────────────────────────────────────────────────────
it('éligible sans reçu existant — clic génère via le service', function () {
    $asso = Association::factory()->create([
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

    $sousCat = makeSousCatDon($asso);
    $ligne = makeDonLigne($asso, $tiers, $sousCat, '2025-04-01', 75.0);

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

    // Le stub ne crée pas de RecuFiscalEmis en base — on vérifie stabilité
    expect(RecuFiscalEmis::count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Bouton reçu — caché si asso non éligible
// ─────────────────────────────────────────────────────────────────────────────
it('cache tous les boutons reçu si eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => false,
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $sousCat = makeSousCatDon($asso);
    makeDonLigne($asso, $tiers, $sousCat, '2025-01-10', 50.0);

    $html = Livewire::test(MesDons::class, ['association' => $asso])->html();

    expect($html)->not->toContain('Télécharger le reçu');
});
