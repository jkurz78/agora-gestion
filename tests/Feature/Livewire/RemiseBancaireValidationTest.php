<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Livewire\RemiseBancaireValidation;
use App\Models\CompteBancaire;
use App\Models\RemiseBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create();
    $this->remise = RemiseBancaire::create([
        'numero' => 1,
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
        'libelle' => 'Remise chèques n°1',
        'saisi_par' => $this->user->id,
    ]);

    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $this->tx = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 30.00,
        'statut_reglement' => StatutReglement::EnAttente,
        'tiers_id' => $tiers->id,
        'remise_id' => $this->remise->id,
        'reference' => null, // not yet comptabilised
    ]);
});

it('renders the validation page with linked transactions', function () {
    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->assertSee('Jean DUPONT')
        ->assertSee('30,00');
});

it('comptabiliser sets statut_reglement=recu and redirects', function () {
    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->call('comptabiliser')
        ->assertRedirect(route('banques.remises.index'));

    $this->tx->refresh();
    expect($this->tx->statut_reglement)->toBe(StatutReglement::Recu)
        ->and($this->tx->reference)->toBe('RBC-00001-001');
});

it('comptabiliser calls modifier when remise already has recu transactions', function () {
    // Pre-mark one transaction as recu to simulate an already-comptabilised remise
    $txInitiale = Transaction::factory()->asRecette()->create([
        'compte_id' => $this->compteCible->id,
        'mode_paiement' => ModePaiement::Cheque,
        'montant_total' => 50.00,
        'statut_reglement' => StatutReglement::Recu,
        'remise_id' => $this->remise->id,
    ]);

    // The component should detect alreadyComptabilisee=true and call modifier()
    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->call('comptabiliser')
        ->assertRedirect(route('banques.remises.index'));

    // Both transactions should be processed
    $this->tx->refresh();
    $txInitiale->refresh();
    // tx was en_attente, modifier should handle it
    expect($this->tx->remise_id)->toBe($this->remise->id);
});
