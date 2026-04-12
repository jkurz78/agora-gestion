<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireShow;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Services\RemiseBancaireService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compteCible = CompteBancaire::factory()->create(['nom' => 'Banque Pop']);

    $sc = SousCategorie::factory()->create();
    $typeOp = TypeOperation::factory()->create(['sous_categorie_id' => $sc->id]);
    $operation = Operation::factory()->create(['nom' => 'Gym', 'type_operation_id' => $typeOp->id]);
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean']);
    $participant = Participant::create([
        'operation_id' => $operation->id,
        'tiers_id' => $tiers->id,
        'date_inscription' => now()->toDateString(),
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2025-10-01',
    ]);
    $reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);

    $service = app(RemiseBancaireService::class);
    $this->remise = $service->creer([
        'date' => '2025-10-15',
        'mode_paiement' => ModePaiement::Cheque->value,
        'compte_cible_id' => $this->compteCible->id,
    ]);
    $service->comptabiliser($this->remise, [$reglement->id]);
    $this->remise->refresh();
});

it('renders the show page', function () {
    $this->get(route('banques.remises.show', $this->remise))
        ->assertStatus(200)
        ->assertSeeLivewire(RemiseBancaireShow::class);
});

it('displays remise details', function () {
    Livewire::test(RemiseBancaireShow::class, ['remise' => $this->remise])
        ->assertSee('Remise chèques n°1')
        ->assertSee('Banque Pop')
        ->assertSee('Jean DUPONT')
        ->assertSee('RBC-00001-001');
});
