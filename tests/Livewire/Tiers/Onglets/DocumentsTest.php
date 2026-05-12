<?php

declare(strict_types=1);

use App\Livewire\Tiers\Onglets\Documents;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Facture;
use App\Models\FacturePartenaireDeposee;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    $this->actingAs(User::factory()->create());
});

// ── Task 7.3.2 : rendu section reçus fiscaux ─────────────────────────────────

it('affiche la section Reçus fiscaux quand le tiers en a', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
        'numero' => 'RF-2025-0001',
    ]);

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertSee('Reçus fiscaux émis')
        ->assertSee('RF-2025-0001');
});

// ── Task 7.3.3 : masquage section vide ───────────────────────────────────────

it('masque la section Factures émises si aucune facture', function (): void {
    $tiers = Tiers::factory()->create();
    // Seulement un reçu fiscal, pas de facture
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
    ]);

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertSee('Reçus fiscaux émis')
        ->assertDontSee('Factures émises')
        ->assertDontSee('Factures partenaires déposées')
        ->assertDontSee('Justificatifs participants')
        ->assertDontSee('Pièces jointes comptables');
});

// ── Task 7.3.4 : affichage 5 sections ────────────────────────────────────────

it('affiche les 5 sections quand toutes ont du contenu', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => 'a.pdf',
        'libelle' => 'Test',
    ]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
    ]);
    Facture::factory()->create(['tiers_id' => $tiers->id]);
    FacturePartenaireDeposee::factory()->create(['tiers_id' => $tiers->id]);
    $participant = Participant::factory()->create(['tiers_id' => $tiers->id]);
    ParticipantDocument::factory()->create(['participant_id' => $participant->id]);

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertSee('Reçus fiscaux émis')
        ->assertSee('Factures émises')
        ->assertSee('Factures partenaires déposées')
        ->assertSee('Justificatifs participants')
        ->assertSee('Pièces jointes comptables');
});

// ── Task 7.3.5 : badge type don/cotisation ───────────────────────────────────

it('affiche le badge Cotisation pour un reçu lié à une adhésion', function (): void {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->create(['tiers_id' => $tiers->id]);
    $ligne = TransactionLigne::factory()->create(['transaction_id' => $tx->id]);
    Adhesion::factory()->create(['tiers_id' => $tiers->id, 'transaction_id' => $tx->id]);
    RecuFiscalEmis::factory()->create([
        'tiers_id' => $tiers->id,
        'transaction_ligne_id' => $ligne->id,
        'annule_at' => null,
    ]);

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertSee('Cotisation');
});

// ── Task 7.3.6 : lien participant + affichage nom ────────────────────────────

it('rend le nom du participant dans la section justificatifs', function (): void {
    $tiersDupont = Tiers::factory()->create(['nom' => 'DUPONT', 'prenom' => 'Marie']);
    $participant = Participant::factory()->create(['tiers_id' => $tiersDupont->id]);
    ParticipantDocument::factory()->create([
        'participant_id' => $participant->id,
        'label' => 'Attestation médicale',
    ]);

    Livewire::test(Documents::class, ['tiers' => $tiersDupont])
        ->assertSee('Attestation médicale')
        ->assertSee('Marie')
        ->assertSee('DUPONT');
});

// ── Task 7.3.7 : PJ niveaux transaction vs ligne ─────────────────────────────

it('distingue PJ niveau transaction vs niveau ligne', function (): void {
    $tiers = Tiers::factory()->create();
    Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => 'tx.pdf',
        'libelle' => 'TX-PJ',
    ]);
    $tx2 = Transaction::factory()->create([
        'tiers_id' => $tiers->id,
        'piece_jointe_path' => null,
        'libelle' => 'TX-LIGNE',
    ]);
    TransactionLigne::factory()->create([
        'transaction_id' => $tx2->id,
        'piece_jointe_path' => 'ligne.pdf',
    ]);

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertSee('Transaction')
        ->assertSee('Ligne')
        ->assertSee('TX-PJ')
        ->assertSee('TX-LIGNE');
});

// ── Task 7.3.8 : vue vide ─────────────────────────────────────────────────────

it('rend la vue vide quand le tiers n\'a aucun document', function (): void {
    $tiers = Tiers::factory()->create();

    Livewire::test(Documents::class, ['tiers' => $tiers])
        ->assertDontSee('Reçus fiscaux émis')
        ->assertDontSee('Factures émises')
        ->assertDontSee('Factures partenaires déposées')
        ->assertDontSee('Justificatifs participants')
        ->assertDontSee('Pièces jointes comptables');
});
