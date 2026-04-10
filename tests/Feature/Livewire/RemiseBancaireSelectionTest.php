<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireSelection;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
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

    $this->sousCategorie = SousCategorie::factory()->create();
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $this->sousCategorie->id]);
    $this->operation = Operation::factory()->create([
        'nom' => 'Gym Seniors',
        'type_operation_id' => $typeOp->id,
    ]);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $participant = Participant::create([
        'operation_id' => $this->operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
        'date' => '2025-10-01',
    ]);
    $this->reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);
});

it('renders the selection page', function () {
    $this->get(route('compta.banques.remises.selection', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireSelection::class);
});

it('shows available reglements with matching mode_paiement', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertSee('Jean DUPONT')
        ->assertSee('Gym Seniors')
        ->assertSee('30,00');
});

it('does not show reglements with different mode_paiement', function () {
    $this->reglement->update(['mode_paiement' => ModePaiement::Especes->value]);

    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->assertDontSee('Jean DUPONT');
});

it('toggles reglement selection', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleReglement', $this->reglement->id)
        ->assertSet('selectedIds', [$this->reglement->id]);
});

it('redirects to validation with selected ids', function () {
    Livewire::test(RemiseBancaireSelection::class, ['remise' => $this->remise])
        ->call('toggleReglement', $this->reglement->id)
        ->call('valider')
        ->assertRedirect();
});
