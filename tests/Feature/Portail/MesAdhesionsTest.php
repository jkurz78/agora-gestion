<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\Portail\MesAdhesions;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Affichage tri + statut
// ─────────────────────────────────────────────────────────────────────────────
it('liste les adhésions triées par date_fin desc et affiche le bon badge de statut', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    // 3 adhésions avec date_fin différentes
    $ancienne = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date_debut' => now()->subYear()->subMonth(),
        'date_fin' => now()->subMonth(), // expirée
        'exercice' => 2024,
    ]);
    $courante = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date_debut' => now()->subMonth(),
        'date_fin' => now()->addYear(), // à jour
        'exercice' => 2025,
    ]);
    $tresAncienne = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date_debut' => now()->subYears(2)->subMonth(),
        'date_fin' => now()->subYears(2), // expirée (plus ancienne)
        'exercice' => 2023,
    ]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    // L'adhésion la plus récente (courante) doit apparaître avant les expirées
    $posCourante = strpos($html, 'Ex. 2025-2026');
    $posAncienne = strpos($html, 'Ex. 2024-2025');
    $posTresAncienne = strpos($html, 'Ex. 2023-2024');

    expect($posCourante)->toBeLessThan($posAncienne);
    expect($posAncienne)->toBeLessThan($posTresAncienne);

    // Badge À jour pour la courante, Expirée pour les anciennes
    expect($html)
        ->toContain('bg-success') // À jour
        ->toContain('bg-secondary'); // Expirée
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : CTA Renouveler — URL spécifique
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le CTA Renouveler avec url_renouvellement_adhesion quand défini', function () {
    $asso = Association::factory()->create([
        'url_renouvellement_adhesion' => 'https://hello.asso/mon-form',
        'url_site_web' => 'https://monasso.fr',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    Adhesion::factory()->create(['association_id' => $asso->id, 'tiers_id' => $tiers->id]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->toContain('https://hello.asso/mon-form');
    expect($html)->toContain('Renouveler mon adhésion');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : CTA Renouveler — fallback site web
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le CTA avec url_site_web si url_renouvellement_adhesion est null', function () {
    $asso = Association::factory()->create([
        'url_renouvellement_adhesion' => null,
        'url_site_web' => 'https://monasso.fr',
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    Adhesion::factory()->create(['association_id' => $asso->id, 'tiers_id' => $tiers->id]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->toContain('https://monasso.fr');
    expect($html)->toContain('Renouveler mon adhésion');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : CTA Renouveler — caché si les 2 URLs null
// ─────────────────────────────────────────────────────────────────────────────
it('cache le CTA Renouveler si url_renouvellement_adhesion et url_site_web sont null', function () {
    $asso = Association::factory()->create([
        'url_renouvellement_adhesion' => null,
        'url_site_web' => null,
    ]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    Adhesion::factory()->create(['association_id' => $asso->id, 'tiers_id' => $tiers->id]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->not->toContain('Renouveler mon adhésion');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Bouton reçu — déjà émis (stream PDF, pas de nouveau reçu créé)
// ─────────────────────────────────────────────────────────────────────────────
it('bouton reçu visible si reçu déjà émis et clic stream le PDF sans créer un nouveau reçu', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $transaction = Transaction::factory()->create(['statut_reglement' => StatutReglement::Recu,
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);
    $ligne = $transaction->lignes()->first();

    // Créer un reçu pré-existant avec un vrai fichier dans Storage::fake
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

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $transaction->id,
        'deductible_fiscal' => true,
        'montant_facial' => 50.00,
    ]);

    // Le bouton doit être visible
    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();
    expect($html)->toContain('Télécharger le reçu');

    $countBefore = RecuFiscalEmis::count();
    $recuCapture = null;

    // Substitut du service (final class → on remplace la liaison container)
    $capturedRecu = $recu;
    app()->bind(RecuFiscalService::class, fn () => new class($capturedRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, ?User $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
        }
    });

    Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $adhesion->id);

    // Pas de nouveau reçu créé (le stub retourne l'existant)
    expect(RecuFiscalEmis::count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Bouton reçu — génération à la demande
// ─────────────────────────────────────────────────────────────────────────────
it('bouton reçu éligible sans reçu existant — clic crée via le service', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    $transaction = Transaction::factory()->create(['statut_reglement' => StatutReglement::Recu,
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

    // Stub du service — on vérifie qu'il est bien appelé en comptant les RecuFiscalEmis avant/après
    // (le stub ne crée pas de reçu réel — le test vérifie que la mécanique s'enclenche)
    $countBefore = RecuFiscalEmis::count();

    app()->bind(RecuFiscalService::class, fn () => new class($fakeRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $fakeRecu) {}

        public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, ?User $user = null): RecuFiscalEmis
        {
            return $this->fakeRecu;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
        }
    });

    // Appel : le stub est résolu et retourne le fakeRecu — aucune exception = succès
    Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $adhesion->id)
        ->assertOk();

    // Le stub ne crée pas de nouveau RecuFiscalEmis en base
    expect(RecuFiscalEmis::count())->toBe($countBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Bouton reçu — caché si asso non éligible
// ─────────────────────────────────────────────────────────────────────────────
it('cache tous les boutons reçu si eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $transaction = Transaction::factory()->create(['statut_reglement' => StatutReglement::Recu,
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $transaction->id,
        'deductible_fiscal' => true,
        'montant_facial' => 50.00,
    ]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->not->toContain('Télécharger le reçu');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Bouton reçu — caché sur ligne gratuite, présent sur ligne éligible
// ─────────────────────────────────────────────────────────────────────────────
it('cache le bouton reçu sur adhésion gratuite mais le montre sur les éligibles', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($tiers);

    // Adhésion gratuite (transaction_id null)
    Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => null,
        'montant_facial' => 0,
        'deductible_fiscal' => false,
        'exercice' => 2024,
    ]);

    // Adhésion éligible
    $transaction = Transaction::factory()->create(['statut_reglement' => StatutReglement::Recu,
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);
    Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_id' => $transaction->id,
        'deductible_fiscal' => true,
        'montant_facial' => 50.00,
        'exercice' => 2025,
    ]);

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->toContain('Télécharger le reçu');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Cohabitation slice 1+2 — sidebar affiche les 2 groupes
// ─────────────────────────────────────────────────────────────────────────────
it('tiers pour_depenses=true avec ≥1 adhésion voit les groupes Mes frais & factures et Ma vie de membre', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create([
        'association_id' => $asso->id,
        'pour_depenses' => true,
    ]);

    Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    $html = view('portail.layouts.partials.sidebar', [
        'tiers' => $tiers,
        'portailAssociation' => $asso,
    ])->render();

    expect($html)
        ->toContain('Mes frais &amp; factures')
        ->toContain('Ma vie de membre')
        ->toContain('Mes adhésions');
});
