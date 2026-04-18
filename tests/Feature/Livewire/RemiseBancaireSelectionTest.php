<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\RemiseBancaireSelection;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compteCible = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $this->remise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('renders the selection page', function () {
    $this->get(route('banques.remises.selection', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireSelection::class);
});

it('shows transactions matching mode_paiement and statut_reglement', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Jean']);
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'remise_id' => null,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertSee('Jean DUPONT')
        ->assertSee('30,00');
});

it('does not show transactions with different mode_paiement', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Jean']);
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Especes,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'remise_id' => null,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertDontSee('Jean DUPONT');
});

it('does not show transactions with statut_reglement=pointe', function () {
    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Dupont', 'prenom' => 'Jean']);
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::Pointe,
        'tiers_id' => $tiers->id,
        'remise_id' => null,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertDontSee('Jean DUPONT');
});

it('toggleTransaction sélectionne et désélectionne une transaction', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 45.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'remise_id' => null,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleTransaction', $tx->id)
        ->assertSet('selectedTransactionIds', [$tx->id])
        ->call('toggleTransaction', $tx->id)
        ->assertSet('selectedTransactionIds', []);
});

it('pre-popule selectedTransactionIds avec les transactions déjà dans la remise', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 55.00,
        'statut_reglement' => StatutReglement::Recu,
        'remise_id' => $this->remise->id,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertSet('selectedTransactionIds', [$tx->id]);
});

it('valider enregistre le brouillon et redirige vers show', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 45.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'remise_id' => null,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleTransaction', $tx->id)
        ->call('valider')
        ->assertRedirect(route('banques.remises.show', $this->remise));

    $tx->refresh();
    expect($tx->remise_id)->toBe($this->remise->id);
});

it('valider sans sélection affiche une erreur', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('valider')
        ->assertHasErrors(['selection']);
});

it('exclut les transactions déjà dans une autre remise', function () {
    $autreRemise = RemiseBancaire::create([
        'association_id' => $this->association->id,
        'numero' => 99,
        'date' => '2025-11-01',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Autre remise',
        'saisi_par' => $this->user->id,
    ]);

    $tiers = Tiers::factory()->create(['association_id' => $this->association->id, 'nom' => 'Martin', 'prenom' => 'Sophie']);
    Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 60.00,
        'statut_reglement' => StatutReglement::Recu,
        'tiers_id' => $tiers->id,
        'remise_id' => $autreRemise->id,
    ]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertDontSee('Sophie MARTIN');
});
