<?php

declare(strict_types=1);

use App\Enums\TypeLigneFacture;
use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\DocumentPrevisionnel;
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
use App\Support\PortailRoute;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeAssoDocTest(): Association
{
    return Association::factory()->create(['slug' => 'asso-doc-test']);
}

function makeOperationDocTest(Association $asso, string $seancesFutures = 'all'): array
{
    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $operation = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Formation Test',
        'date_debut' => now()->subMonth(),
        'date_fin' => now()->addMonth(),
    ]);

    if ($seancesFutures === 'all') {
        // Toutes futures → À venir
        Seance::factory()->create([
            'association_id' => $asso->id,
            'operation_id' => $operation->id,
            'date' => now()->addMonth(),
            'numero' => 1,
        ]);
    } elseif ($seancesFutures === 'mixed') {
        // Une passée, une future → En cours
        Seance::factory()->create([
            'association_id' => $asso->id,
            'operation_id' => $operation->id,
            'date' => now()->subMonth(),
            'numero' => 1,
        ]);
        Seance::factory()->create([
            'association_id' => $asso->id,
            'operation_id' => $operation->id,
            'date' => now()->addMonth(),
            'numero' => 2,
        ]);
    } elseif ($seancesFutures === 'none') {
        // Toutes passées → Terminée
        Seance::factory()->create([
            'association_id' => $asso->id,
            'operation_id' => $operation->id,
            'date' => now()->subMonth(),
            'numero' => 1,
        ]);
    }

    return [$typeOp, $operation];
}

function makeParticipantDocTest(Association $asso, Operation $operation, Tiers $tiers): Participant
{
    return Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
    ]);
}

function attachDevisToParticipant(Association $asso, Participant $participant): DocumentPrevisionnel
{
    return DocumentPrevisionnel::factory()->devis()->create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'operation_id' => $participant->operation_id,
        'numero' => 'DV-2026-001',
    ]);
}

function attachFactureToParticipant(Association $asso, Participant $participant): Facture
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
    Reglement::factory()->create([
        'participant_id' => $participant->id,
        'seance_id' => $seance?->id ?? Seance::factory()->create(['association_id' => $asso->id, 'operation_id' => $participant->operation_id, 'numero' => 99])->id,
    ]);

    // Link transaction to reglement
    $reglement = Reglement::where('participant_id', $participant->id)->first();
    $transaction->update(['reglement_id' => $reglement->id]);

    $facture = Facture::factory()->validee()->create([
        'association_id' => $asso->id,
        'tiers_id' => $participant->tiers_id,
        'numero' => 'FA-2026-001',
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

// ─────────────────────────────────────────────────────────────────────────────
// Test 1 : À venir — bouton "Voir le devis" affiché si devis rattaché
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bouton Voir le devis sur la carte À venir quand un devis est rattaché', function () {
    $asso = makeAssoDocTest();
    TenantContext::boot($asso);

    [, $operation] = makeOperationDocTest($asso, 'all');
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantDocTest($asso, $operation, $tiers);
    $devis = attachDevisToParticipant($asso, $participant);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Voir le devis');
    expect($html)->toContain(PortailRoute::to('documents.devis', $asso, ['document' => $devis->id]));
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 2 : En cours — bouton "Voir la facture en cours" affiché
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bouton Voir la facture en cours sur la carte En cours quand une facture est rattachée', function () {
    $asso = makeAssoDocTest();
    TenantContext::boot($asso);

    [, $operation] = makeOperationDocTest($asso, 'mixed');
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantDocTest($asso, $operation, $tiers);
    $facture = attachFactureToParticipant($asso, $participant);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Voir la facture en cours');
    expect($html)->toContain(PortailRoute::to('documents.facture', $asso, ['facture' => $facture->id]));
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 3 : Terminée — bouton "Voir la facture finale" affiché
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bouton Voir la facture finale sur la carte Terminée quand une facture est rattachée', function () {
    $asso = makeAssoDocTest();
    TenantContext::boot($asso);

    [, $operation] = makeOperationDocTest($asso, 'none');
    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = makeParticipantDocTest($asso, $operation, $tiers);
    $facture = attachFactureToParticipant($asso, $participant);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->toContain('Voir la facture finale');
    expect($html)->toContain(PortailRoute::to('documents.facture', $asso, ['facture' => $facture->id]));
});

// ─────────────────────────────────────────────────────────────────────────────
// Test 4 : Pas de devis/facture → boutons absents
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas les boutons devis/facture quand aucun document n\'est rattaché', function () {
    $asso = makeAssoDocTest();
    TenantContext::boot($asso);

    // Crée 3 participants dans les 3 sections sans docs
    [, $opAvenir] = makeOperationDocTest($asso, 'all');
    [, $opEncours] = makeOperationDocTest($asso, 'mixed');
    [, $opTerminee] = makeOperationDocTest($asso, 'none');

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    makeParticipantDocTest($asso, $opAvenir, $tiers);
    makeParticipantDocTest($asso, $opEncours, $tiers);
    makeParticipantDocTest($asso, $opTerminee, $tiers);

    $html = Livewire::test(MesActivites::class, ['association' => $asso])
        ->assertStatus(200)
        ->html();

    expect($html)->not->toContain('Voir le devis');
    expect($html)->not->toContain('Voir la facture en cours');
    expect($html)->not->toContain('Voir la facture finale');
});
