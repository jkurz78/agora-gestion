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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dossier d'intrusion — Portail Tiers Slice 2 (Mes dons).
 *
 * Trois scénarios critiques :
 *   8. Intrusion intra-asso : Alice ne peut pas télécharger le reçu de Bob (403).
 *   9. Intrusion cross-tenant : TenantScope filtre la ligne de l'asso B → 403.
 *  10. Logger : téléchargement éligible émet Log::info avec ligne_id + tiers_id.
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

function makeEligibleDonLigne(Association $asso, Tiers $tiers, string $date = '2025-06-01'): TransactionLigne
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);
    $sousCat->usages()->create(['usage' => UsageComptable::Don->value]);

    $tx = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'date' => $date,
        'statut_reglement' => StatutReglement::Recu,
        'type' => TypeTransaction::Recette->value,
    ]);

    return TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100.0,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 8 : Intrusion intra-asso — Alice ne peut pas télécharger le reçu de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 quand elle appelle telechargerRecuFiscal avec la ligne de Bob', function () {
    $asso = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
    ]);
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id, 'email' => 'alice@ex.org']);
    $bob = Tiers::factory()->create([
        'association_id' => $asso->id,
        'email' => 'bob@ex.org',
        'adresse_ligne1' => '1 rue test',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);

    $bobLigne = makeEligibleDonLigne($asso, $bob);

    Auth::guard('tiers-portail')->login($alice);

    $result = Livewire::test(MesDons::class, ['association' => $asso])
        ->call('telechargerRecuFiscal', $bobLigne->id);

    // forTiers($alice) ne retournera pas la ligne de Bob → abort_unless → 403
    $result->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Intrusion cross-tenant — ligne asso B invisible depuis asso A
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] cross-tenant 403 — TenantScope filtre la ligne d\'un autre tenant', function () {
    $assoA = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Jean Test',
        'signataire_qualite' => 'Président',
    ]);
    $assoB = Association::factory()->create([
        'eligible_recu_fiscal' => true,
        'signataire_nom' => 'Marie Autre',
        'signataire_qualite' => 'Trésorière',
    ]);

    // Créer la ligne dans asso B
    TenantContext::boot($assoB);
    $tiersB = Tiers::factory()->create([
        'association_id' => $assoB->id,
        'adresse_ligne1' => '2 rue B',
        'code_postal' => '69001',
        'ville' => 'Lyon',
    ]);
    $ligneB = makeEligibleDonLigne($assoB, $tiersB);

    // Alice se connecte sur portail asso A
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create([
        'association_id' => $assoA->id,
        'adresse_ligne1' => '1 rue A',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($alice);

    $result = Livewire::test(MesDons::class, ['association' => $assoA])
        ->call('telechargerRecuFiscal', $ligneB->id);

    // TenantScope fail-closed : forTiers($alice, assoA) ne retourne pas ligneB → 403
    $result->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Logger — appel éligible émet l'event de log attendu
// ─────────────────────────────────────────────────────────────────────────────
it('[log] telechargerRecuFiscal émet Log::info avec ligne_id et tiers_id', function () {
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

    $ligne = makeEligibleDonLigne($asso, $tiers);

    $fakeRecu = new RecuFiscalEmis(['id' => 1]);
    app()->bind(RecuFiscalService::class, fn () => new class($fakeRecu)
    {
        public function __construct(private readonly RecuFiscalEmis $fakeRecu) {}

        public function obtenirOuGenerer(TransactionLigne $l, mixed $user = null): RecuFiscalEmis
        {
            return $this->fakeRecu;
        }

        public function streamPdf(RecuFiscalEmis $recu): Response
        {
            return response('%PDF-fake', 200, ['Content-Type' => 'application/pdf']);
        }
    });

    Log::spy();

    Livewire::test(MesDons::class, ['association' => $asso])
        ->call('telechargerRecuFiscal', $ligne->id);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($ligne, $tiers): bool {
            return $key === 'portail.recu.fiscal.telecharge'
                && (int) ($context['ligne_id'] ?? null) === (int) $ligne->id
                && (int) ($context['tiers_id'] ?? null) === (int) $tiers->id;
        });
});
