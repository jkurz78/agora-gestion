<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Livewire\AnalysePivot;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    view()->share('espace', Espace::Gestion);
});

it('renders the analyse pivot component', function () {
    Livewire::test(AnalysePivot::class)
        ->assertOk()
        ->assertSee('Analyse');
});

it('returns participants data with correct fields', function () {
    $typeOp = TypeOperation::factory()->create();
    $operation = Operation::factory()->create([
        'type_operation_id' => $typeOp->id,
    ]);
    $tiers = Tiers::factory()->create([
        'nom' => 'Dupont',
        'prenom' => 'Marie',
        'ville' => 'Paris',
    ]);
    $participant = Participant::create([
        'tiers_id' => $tiers->id,
        'operation_id' => $operation->id,
        'date_inscription' => '2026-01-15',
    ]);
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'date' => '2026-01-20',
        'titre' => 'Séance test',
    ]);
    Reglement::create([
        'participant_id' => $participant->id,
        'seance_id' => $seance->id,
        'mode_paiement' => 'cb',
        'montant_prevu' => 25.00,
    ]);

    $component = Livewire::test(AnalysePivot::class);
    $data = $component->get('participantsData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Opération', 'Type opération', 'Séance', 'Nom', 'Prénom',
        'Ville', 'Mode paiement', 'Montant prévu',
    ]);
    expect($data[0]['Nom'])->toBe('Dupont');
    expect($data[0]['Montant prévu'])->toBe(25.0);
});

it('returns financier data with correct fields', function () {
    $compte = CompteBancaire::factory()->create();
    $tiers = Tiers::factory()->create(['nom' => 'Fournisseur', 'pour_depenses' => true]);
    $sousCategorie = SousCategorie::factory()->create();
    $transaction = Transaction::create([
        'tiers_id' => $tiers->id,
        'compte_id' => $compte->id,
        'type' => 'depense',
        'date' => '2026-01-15',
        'libelle' => 'Test',
        'montant_total' => 100.00,
        'mode_paiement' => 'virement',
        'saisi_par' => $this->user->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $sousCategorie->id,
        'montant' => 100.00,
    ]);

    $component = Livewire::test(AnalysePivot::class)
        ->set('activeView', 'financier');
    $data = $component->get('financierData');

    expect($data)->toBeArray()->not->toBeEmpty();
    expect($data[0])->toHaveKeys([
        'Tiers', 'Date', 'Montant', 'Sous-catégorie', 'Catégorie', 'Type', 'Compte',
    ]);
    expect($data[0]['Montant'])->toBe(100.0);
});
