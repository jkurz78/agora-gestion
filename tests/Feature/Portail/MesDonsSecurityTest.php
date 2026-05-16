<?php

declare(strict_types=1);

use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
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

/**
 * Dossier d'intrusion — Portail Tiers Slice 2 (Mes dons).
 *
 * Les tests d'intrusion passent désormais par les routes HTTP
 * (RecuPortailController) au lieu des actions Livewire supprimées.
 *
 *   8. Intrusion intra-asso : Alice ne peut pas voir le reçu de Bob (403).
 *   9. Intrusion cross-tenant : TiensDonsTimelineService filtre → 403.
 *  10. Logger : GET éligible émet Log::info avec ligne_id + tiers_id.
 */
beforeEach(function () {
    TenantContext::clear();
    Storage::fake('local');
});

afterEach(function () {
    TenantContext::clear();
});

function makeEligibleDonLigneHttp(Association $asso, Tiers $tiers, string $date = '2025-06-01'): TransactionLigne
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
// Test 8 : Intrusion intra-asso — Alice ne peut pas voir le reçu de Bob
// ─────────────────────────────────────────────────────────────────────────────
it('[intrusion] Alice 403 GET recus.fiscal avec la ligne de Bob', function () {
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

    $bobLigne = makeEligibleDonLigneHttp($asso, $bob);

    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    // forTiers($alice) ne retournera pas la ligne de Bob → abort_unless → 403
    $url = route('portail.recus.fiscal', ['association' => $asso->slug, 'ligne' => $bobLigne->id]);
    $this->get($url)->assertForbidden();
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
    $ligneB = makeEligibleDonLigneHttp($assoB, $tiersB);

    // Alice se connecte sur portail asso A
    TenantContext::boot($assoA);
    $alice = Tiers::factory()->create([
        'association_id' => $assoA->id,
        'adresse_ligne1' => '1 rue A',
        'code_postal' => '75001',
        'ville' => 'Paris',
    ]);
    Auth::guard('tiers-portail')->login($alice);
    session(['portail.last_activity_at' => now()->timestamp]);

    // Garde cross-tenant dans controller (association_id check) + forTiers filtre → 403
    $url = route('portail.recus.fiscal', ['association' => $assoA->slug, 'ligne' => $ligneB->id]);
    $this->get($url)->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 10 : Logger — GET éligible émet l'event de log attendu
// ─────────────────────────────────────────────────────────────────────────────
it('[log] GET recus.fiscal éligible émet Log::info avec ligne_id et tiers_id', function () {
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

    $ligne = makeEligibleDonLigneHttp($asso, $tiers);

    // Créer un faux PDF dans le storage
    $fakePdf = '%PDF-1.4 fake log test';
    $pdfPath = "recus_fiscaux/2025/test-{$ligne->id}.pdf";
    Storage::disk('local')->put("associations/{$asso->id}/{$pdfPath}", $fakePdf);

    $recu = RecuFiscalEmis::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'pdf_path' => $pdfPath,
        'pdf_hash' => hash('sha256', $fakePdf),
    ]);

    app()->bind(RecuFiscalService::class, fn () => new class($recu)
    {
        public function __construct(private readonly RecuFiscalEmis $existant) {}

        public function obtenirOuGenerer(TransactionLigne $l, mixed $user = null): RecuFiscalEmis
        {
            return $this->existant;
        }
    });

    Log::spy();

    $url = route('portail.recus.fiscal', ['association' => $asso->slug, 'ligne' => $ligne->id]);
    $this->get($url)->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(function (string $key, array $context) use ($ligne, $tiers): bool {
            return $key === 'portail.recu.fiscal.telecharge'
                && (int) ($context['ligne_id'] ?? null) === (int) $ligne->id
                && (int) ($context['tiers_id'] ?? null) === (int) $tiers->id;
        });
});
