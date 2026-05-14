<?php

declare(strict_types=1);

use App\Livewire\Portail\MesAdhesions;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\RecuFiscalService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dossier d'intrusion — Portail Tiers Slice 2 (Mes adhésions).
 *
 * Trois scénarios critiques :
 *   10. Intrusion intra-asso : Alice ne peut pas télécharger le reçu de Bob (403).
 *   11. Intrusion cross-tenant : TenantScope bloque find() → null → 404.
 *   12. Logger : téléchargement éligible émet Log::info avec adhesion_id + tiers_id.
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Intrusion intra-asso — Alice ne peut pas télécharger le reçu de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 quand elle appelle telechargerRecuCotisation avec l\'adhesion de Bob', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'bob@ex.org']);

    $bobAdhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $bob->id,
    ]);

    Auth::guard('tiers-portail')->login($alice);

    $result = Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $bobAdhesion->id);

    // Livewire traduit abort(403) en une erreur 403 sur le composant
    $result->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 11 : Intrusion cross-tenant — adhesion asso B invisible depuis asso A
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] find() retourne null pour une adhesion d\'un autre tenant → 404', function () {
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

    $result = Livewire::test(MesAdhesions::class, ['association' => $assoA])
        ->call('telechargerRecuCotisation', $adhesionB->id);

    // TenantScope fail-closed : Adhesion::find() retourne null → abort(404)
    $result->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 12 : Logger — appel éligible émet l'event de log attendu
// ─────────────────────────────────────────────────────────────────────────────
it('[log] telechargerRecuCotisation émet Log::info avec adhesion_id et tiers_id', function () {
    $asso = Association::factory()->create(['eligible_recu_fiscal' => true]);
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $adhesion = Adhesion::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
    ]);

    // Stub du service (final class → liaison container remplacée)
    $fakeRecu = new RecuFiscalEmis(['id' => 1]);
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

    Log::spy();

    Livewire::test(MesAdhesions::class, ['association' => $asso])
        ->call('telechargerRecuCotisation', $adhesion->id);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($adhesion, $tiers): bool {
            return $key === 'portail.recu.cotisation.telecharge'
                && (int) ($context['adhesion_id'] ?? null) === (int) $adhesion->id
                && (int) ($context['tiers_id'] ?? null) === (int) $tiers->id;
        });
});
