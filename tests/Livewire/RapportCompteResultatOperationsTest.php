<?php

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    session()->forget('exercice_actif');
});

it('se rend avec l\'arbre hiérarchique d\'opérations', function () {
    $op = Operation::factory()->create(['nom' => 'Festival été']);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertOk()
        ->assertViewHas('operationTree');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSeeHtml('lectionnez');
});

it('affiche les données filtrées par opération', function () {
    $op = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Transport')
        ->assertSee('100,00');
});

it('supporte parSeances via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parSeances', false)
        ->set('parSeances', true)
        ->assertSet('parSeances', true);
});

it('supporte parTiers via query string', function () {
    Livewire::test(RapportCompteResultatOperations::class)
        ->assertSet('parTiers', false)
        ->set('parTiers', true)
        ->assertSet('parTiers', true);
});

it('passe les données tiers quand parTiers est actif', function () {
    $op = Operation::factory()->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Frais']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Transport']);
    $tiers = Tiers::factory()->create(['type' => 'particulier', 'nom' => 'dupont', 'prenom' => 'Jean']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'tiers_id' => $tiers->id, 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'montant' => 100.00]);

    Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$op->id])
        ->set('parTiers', true)
        ->assertSee('DUPONT');
});
