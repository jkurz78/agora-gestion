<?php

declare(strict_types=1);

use App\Enums\StatutPresence;
use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\Exercice;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
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
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function monoActivitesSetup(): array
{
    $asso = Association::factory()->create(['slug' => 'svs']);
    TenantContext::boot($asso);

    if (! Exercice::where('annee', 2026)->exists()) {
        Exercice::create([
            'annee' => 2026,
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-08-31',
            'statut' => 'ouvert',
        ]);
    }

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);
    session(['portail.last_activity_at' => now()->timestamp]);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Mono Test',
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'association_id' => $asso->id,
        'date' => now()->subWeek()->toDateString(),
        'numero' => 1,
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'association_id' => $asso->id,
        'date_inscription' => now()->subMonth()->toDateString(),
    ]);
    Presence::create([
        'seance_id' => $seance->id,
        'participant_id' => $participant->id,
        'statut' => StatutPresence::Present->value,
    ]);

    return [$asso, $tiers, $typeOp, $operation, $seance, $participant];
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : Mes activités accessible en mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/mes-activites redirige vers le 1er type alphabétique', function () {
    [$asso, $tiers, $typeOp] = monoActivitesSetup();

    $this->get('/portail/mes-activites')
        ->assertRedirect();
});

it('mode mono: GET /portail/mes-activites/{typeOperation} retourne 200 avec titre Mes ...', function () {
    [$asso, $tiers, $typeOp] = monoActivitesSetup();

    $this->get("/portail/mes-activites/{$typeOp->id}")
        ->assertStatus(200)
        ->assertSeeText('Mes ');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : Téléchargement devis depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/documents/devis/{id} sert le PDF inline', function () {
    [$asso, $tiers, $typeOp, $operation, $seance, $participant] = monoActivitesSetup();

    $devis = DocumentPrevisionnel::factory()->devis()->create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'operation_id' => $operation->id,
        'numero' => 'DV-2026-MONO-'.uniqid(),
    ]);

    $response = $this->get("/portail/documents/devis/{$devis->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toStartWith('%PDF-');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Téléchargement facture depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/documents/facture/{id} sert le PDF inline', function () {
    [$asso, $tiers, $typeOp, $operation, $seance, $participant] = monoActivitesSetup();

    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);

    // Reglement → Participant chain required by ownership check
    $reglement = Reglement::factory()->create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
    ]);

    $transaction = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'reglement_id' => $reglement->id,
    ]);
    $transaction->lignes()->delete();
    $txLigne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100.00,
    ]);

    $facture = Facture::factory()->validee()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'numero' => 'FA-2026-MONO-'.uniqid(),
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'transaction_ligne_id' => $txLigne->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'libelle' => 'Formation',
        'montant' => '100.00',
        'ordre' => 1,
    ]);

    $response = $this->get("/portail/documents/facture/{$facture->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toStartWith('%PDF-');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Téléchargement attestation séance depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/attestations/seance/{operation}/{seance} sert le PDF', function () {
    [$asso, $tiers, $typeOp, $operation, $seance, $participant] = monoActivitesSetup();

    $response = $this->get("/portail/attestations/seance/{$operation->id}/{$seance->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Téléchargement attestation recap depuis mode mono
// ─────────────────────────────────────────────────────────────────────────────
it('mode mono: GET /portail/attestations/recap/{operation}/{participant} sert le PDF', function () {
    [$asso, $tiers, $typeOp, $operation, $seance, $participant] = monoActivitesSetup();

    $response = $this->get("/portail/attestations/recap/{$operation->id}/{$participant->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
});
