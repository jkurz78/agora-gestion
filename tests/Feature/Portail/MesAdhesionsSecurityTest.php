<?php

declare(strict_types=1);

use App\Http\Controllers\Portail\RecuPortailController;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Dossier d'intrusion — Portail Tiers Slice 2 (Mes adhésions).
 *
 * Les tests d'intrusion passent désormais par les routes HTTP
 * (RecuPortailController) au lieu des actions Livewire supprimées.
 *
 *   10. Intrusion intra-asso : Alice ne peut pas voir le reçu de Bob (403).
 *   11. Intrusion cross-tenant : TenantScope bloque le binding → 404.
 *   12. Logger : GET éligible émet Log::info avec adhesion_id + tiers_id.
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Intrusion intra-asso — Alice ne peut pas voir le reçu de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 sur GET recus.cotisation avec l\'adhésion de Bob', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'bob@ex.org']);

    $bobAdhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $bob->id,
    ]);

    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $bobAdhesion->id]);
    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 11 : Intrusion cross-tenant — adhesion asso B invisible depuis asso A
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] find() retourne 404 pour une adhesion d\'un autre tenant via route binding', function () {
    $assoA = Association::factory()->create();
    $assoB = Association::factory()->create();

    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create(['association_id' => $assoB->id]);
    $adhesionB = Adhesion::factory()->create([
        'association_id' => $assoB->id,
        'tiers_id' => $tiersB->id,
    ]);

    // Alice se connecte sur portail asso A
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create(['association_id' => $assoA->id]);
    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    // TenantScope fail-closed : binding Adhesion filtre cross-tenant → 404
    $url = route('portail.recus.cotisation', ['association' => $assoA->slug, 'adhesion' => $adhesionB->id]);
    $this->get($url)->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 12 : Logger — GET éligible émet l'event de log attendu
// ─────────────────────────────────────────────────────────────────────────────
it('[log] GET recus.cotisation éligible émet Log::info avec adhesion_id et tiers_id', function () {
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
    session(['portail.last_activity_at' => now()->timestamp]);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    // Créer un faux PDF dans le storage pour que le controller puisse le lire
    $fakePdf = '%PDF-1.4 fake log test';
    $pdfPath = "recus_fiscaux/2025/test-{$adhesion->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdf);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => null,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdf),
    ]);

    // Stub du service pour éviter la vraie génération PDF
    app()->bind(\App\Services\RecuFiscalService::class, fn () => new class($recu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenererPourAdhesion(Adhesion $adhesion, mixed $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }
    });

    Log::spy();

    $url = route('portail.recus.cotisation', ['association' => $asso->slug, 'adhesion' => $adhesion->id]);
    $this->get($url)->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($adhesion, $tiers): bool {
            return $key === 'portail.recu.cotisation.telecharge'
                && (int) ($context['adhesion_id'] ?? null) === (int) $adhesion->id
                && (int) ($context['tiers_id'] ?? null) === (int) $tiers->id;
        });
});
