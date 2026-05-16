<?php

declare(strict_types=1);

use App\Enums\TypeLigneFacture;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
use App\Models\Exercice;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers partagés
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoCtrlDoc(): Association
{
    return Association::factory()->create(['slug' => 'asso-ctrl-doc-'.uniqid()]);
}

function makeAssoCtrlDocB(): Association
{
    return Association::factory()->create(['slug' => 'asso-ctrl-doc-b-'.uniqid()]);
}

function ensureExercice(Association $asso): void
{
    TenantContext::boot($asso);
    if (! Exercice::where('annee', 2026)->exists()) {
        Exercice::create([
            'annee' => 2026,
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-08-31',
            'statut' => 'ouvert',
        ]);
    }
}

function makeParticipantCtrl(Association $asso, Tiers $tiers): Participant
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $operation->id,
        'date' => now()->addMonth(),
        'numero' => 1,
    ]);

    return Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);
}

function makeDevisCtrl(Association $asso, Participant $participant): DocumentPrevisionnel
{
    return DocumentPrevisionnel::factory()->devis()->create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'operation_id' => $participant->operation_id,
        'numero' => 'DV-2026-CTL-'.uniqid(),
    ]);
}

function makeFactureCtrl(Association $asso, Participant $participant): Facture
{
    $sousCat = SousCategorie::factory()->create(['association_id' => $asso->id]);

    $transaction = Transaction::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $participant->tiers_id,
    ]);
    $transaction->lignes()->delete();

    $txLigne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCat->id,
        'montant' => 100.00,
    ]);

    $seance = Seance::where('operation_id', $participant->operation_id)->first();
    $reglement = Reglement::factory()->create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
    ]);
    $transaction->update(['reglement_id' => $reglement->id]);

    $facture = Facture::factory()->validee()->create([
        'association_id' => $asso->id,
        'tiers_id' => $participant->tiers_id,
        'numero' => 'FA-2026-CTL-'.uniqid(),
    ]);

    FactureLigne::create([
        'facture_id' => $facture->id,
        'transaction_ligne_id' => $txLigne->id,
        'type' => TypeLigneFacture::MontantManuel->value,
        'libelle' => 'Formation',
        'montant' => '100.00',
        'ordre' => 1,
    ]);

    return $facture;
}

function actAsPortailTiers(Tiers $tiers): void
{
    Auth::guard('tiers-portail')->login($tiers);
}

// ─────────────────────────────────────────────────────────────────────────────
// Test 5 : Devis — 200 + Content-Type pdf + inline + %PDF-
// ─────────────────────────────────────────────────────────────────────────────
it('sert le devis en inline PDF pour le tiers propriétaire', function () {
    $asso = makeAssoCtrlDoc();
    ensureExercice($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    $participant = makeParticipantCtrl($asso, $tiers);
    $devis = makeDevisCtrl($asso, $participant);

    actAsPortailTiers($tiers);

    $response = $this->get("/{$asso->slug}/portail/documents/devis/{$devis->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toStartWith('%PDF-');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 6 : Devis — 403 intrusion intra-asso (Alice tente le devis de Bob)
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 403 quand Alice tente d\'accéder au devis de Bob dans la même asso', function () {
    $asso = makeAssoCtrlDoc();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id]);
    $bob = Tiers::factory()->create(['association_id' => $asso->id]);

    $participantBob = makeParticipantCtrl($asso, $bob);
    $devisBob = makeDevisCtrl($asso, $participantBob);

    actAsPortailTiers($alice);

    $response = $this->get("/{$asso->slug}/portail/documents/devis/{$devisBob->id}");

    $response->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 7 : Devis — 404 cross-tenant (Alice asso A tente devis asso B)
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 404 quand Alice asso A tente d\'accéder à un devis de l\'asso B', function () {
    $assoA = makeAssoCtrlDoc();
    $assoB = makeAssoCtrlDocB();

    $alice = Tiers::factory()->for($assoA, 'association')->create();

    TenantContext::boot($assoB);
    $bobB = Tiers::factory()->create(['association_id' => $assoB->id]);
    $participantBob = makeParticipantCtrl($assoB, $bobB);
    $devisB = makeDevisCtrl($assoB, $participantBob);

    TenantContext::boot($assoA);
    actAsPortailTiers($alice);

    // La requête arrive via le slug de asso A — le TenantScope doit renvoyer 404
    $response = $this->get("/{$assoA->slug}/portail/documents/devis/{$devisB->id}");

    $response->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8a : Facture — 200 + inline PDF
// ─────────────────────────────────────────────────────────────────────────────
it('sert la facture en inline PDF pour le tiers propriétaire', function () {
    $asso = makeAssoCtrlDoc();
    ensureExercice($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    $participant = makeParticipantCtrl($asso, $tiers);
    $facture = makeFactureCtrl($asso, $participant);

    actAsPortailTiers($tiers);

    $response = $this->get("/{$asso->slug}/portail/documents/facture/{$facture->id}");

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toStartWith('%PDF-');
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8b : Facture — 403 intrusion intra-asso
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 403 quand Alice tente d\'accéder à la facture de Bob dans la même asso', function () {
    $asso = makeAssoCtrlDoc();
    TenantContext::boot($asso);

    $alice = Tiers::factory()->create(['association_id' => $asso->id]);
    $bob = Tiers::factory()->create(['association_id' => $asso->id]);

    $participantBob = makeParticipantCtrl($asso, $bob);
    $factureBob = makeFactureCtrl($asso, $participantBob);

    actAsPortailTiers($alice);

    $response = $this->get("/{$asso->slug}/portail/documents/facture/{$factureBob->id}");

    $response->assertStatus(403);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 8c : Facture — 404 cross-tenant
// ─────────────────────────────────────────────────────────────────────────────
it('retourne 404 quand Alice asso A tente d\'accéder à une facture de l\'asso B', function () {
    $assoA = makeAssoCtrlDoc();
    $assoB = makeAssoCtrlDocB();

    $alice = Tiers::factory()->for($assoA, 'association')->create();

    TenantContext::boot($assoB);
    $bobB = Tiers::factory()->create(['association_id' => $assoB->id]);
    $participantBob = makeParticipantCtrl($assoB, $bobB);
    $factureB = makeFactureCtrl($assoB, $participantBob);

    TenantContext::boot($assoA);
    actAsPortailTiers($alice);

    $response = $this->get("/{$assoA->slug}/portail/documents/facture/{$factureB->id}");

    $response->assertStatus(404);
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 9 : Logger émis portail.devis.telecharge + portail.facture.telecharge
// ─────────────────────────────────────────────────────────────────────────────
it('émet les événements de log portail.devis.telecharge et portail.facture.telecharge', function () {
    $asso = makeAssoCtrlDoc();
    ensureExercice($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    $participant = makeParticipantCtrl($asso, $tiers);
    $devis = makeDevisCtrl($asso, $participant);
    $facture = makeFactureCtrl($asso, $participant);

    Log::spy();

    actAsPortailTiers($tiers);

    $this->get("/{$asso->slug}/portail/documents/devis/{$devis->id}")->assertStatus(200);
    $this->get("/{$asso->slug}/portail/documents/facture/{$facture->id}")->assertStatus(200);

    Log::shouldHaveReceived('info')
        ->with('portail.devis.telecharge', Mockery::on(fn ($ctx) => isset($ctx['document_id']) && isset($ctx['tiers_id'])));

    Log::shouldHaveReceived('info')
        ->with('portail.facture.telecharge', Mockery::on(fn ($ctx) => isset($ctx['facture_id']) && isset($ctx['tiers_id'])));
});
