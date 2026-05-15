<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Livewire\Portail\MesAdhesions;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Support\PortailRoute;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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
// Test 5 : Bouton reçu — déjà émis (href vers route, pas wire:click)
// ─────────────────────────────────────────────────────────────────────────────
it('bouton reçu visible si reçu déjà émis — href vers recus.cotisation sans wire:click', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
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
    $ligne = $transaction->lignes()->first();

    // Créer un reçu pré-existant
    $fakePdfContent = '%PDF-1.4 fake';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdfContent);

    RecuFiscalEmis::factory()->create([
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

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    // Le bouton est désormais un lien <a> vers la route HTTP
    expect($html)->toContain('Voir le reçu');
    expect($html)->toContain('recus/cotisation/'.$adhesion->id);
    expect($html)->toContain('target="_blank"');
    // Pas de wire:click résiduel
    expect($html)->not->toContain('wire:click="telechargerRecuCotisation');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Bouton reçu — éligible sans reçu existant — href présent
// ─────────────────────────────────────────────────────────────────────────────
it('adhésion éligible sans reçu existant — href vers route recus.cotisation présent', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
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

    $html = Livewire::test(MesAdhesions::class, ['association' => $asso])->html();

    expect($html)->toContain('Voir le reçu');
    expect($html)->toContain('recus/cotisation/'.$adhesion->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Bouton reçu — caché si asso non éligible
// ─────────────────────────────────────────────────────────────────────────────
it('cache tous les boutons reçu si eligible_recu_fiscal est false', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => false]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $transaction = Transaction::factory()->create([
        'statut_reglement' => StatutReglement::Recu,
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

    expect($html)->not->toContain('Voir le reçu');
    expect($html)->not->toContain('recus/cotisation');
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
    $transaction = Transaction::factory()->create([
        'statut_reglement' => StatutReglement::Recu,
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

    expect($html)->toContain('Voir le reçu');
    expect($html)->toContain('recus/cotisation');
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
