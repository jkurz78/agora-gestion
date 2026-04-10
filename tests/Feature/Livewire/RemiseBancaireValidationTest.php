<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Livewire\RemiseBancaireValidation;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
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
    $this->reglement = Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => ModePaiement::Cheque->value,
        'montant_prevu' => 30.00,
    ]);
});

it('renders the validation page with session data', function () {
    session(['remise_selected_ids' => [$this->reglement->id]]);

    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->assertSee('Jean DUPONT')
        ->assertSee('30,00');
});

it('comptabiliser creates transactions and redirects', function () {
    session(['remise_selected_ids' => [$this->reglement->id]]);

    Livewire::test(RemiseBancaireValidation::class, ['remise' => $this->remise])
        ->call('comptabiliser')
        ->assertRedirect(route('compta.banques.remises.index'));

    expect(Transaction::where('remise_id', $this->remise->id)->count())->toBe(1);
    $this->remise->refresh();
    expect($this->remise->virement_id)->not->toBeNull();
});
