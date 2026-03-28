<?php

use App\Livewire\RapportSeances;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\SousCategorie;
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

it('se rend avec la liste des opérations ayant des séances', function () {
    $opAvec = Operation::factory()->withSeances(2)->create(['code' => 'FEST', 'nom' => 'Festival']);
    $opSans = Operation::factory()->create(['nombre_seances' => null, 'code' => 'INVIS', 'nom' => 'Invisible']);

    Livewire::test(RapportSeances::class)
        ->assertSee('FEST')
        ->assertDontSee('INVIS');
});

it('affiche un message si aucune opération sélectionnée', function () {
    Livewire::test(RapportSeances::class)
        ->assertSee('Sélectionnez au moins une opération');
});

it('affiche les colonnes séances et le total', function () {
    $op = Operation::factory()->withSeances(2)->create();
    $cat = Categorie::factory()->depense()->create(['nom' => 'Charges']);
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Location salle']);

    $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
    $d->lignes()->forceDelete();
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 2, 'montant' => 150.00]);

    Livewire::test(RapportSeances::class)
        ->set('selectedOperationIds', [$op->id])
        ->assertSee('Location salle')
        ->assertSee('Séance 1')
        ->assertSee('Séance 2')
        ->assertSee('100,00')
        ->assertSee('150,00')
        ->assertSee('250,00'); // total
});

it('agrège les séances de même numéro sur plusieurs opérations', function () {
    $op1 = Operation::factory()->withSeances(1)->create();
    $op2 = Operation::factory()->withSeances(1)->create();
    $cat = Categorie::factory()->depense()->create();
    $sc = SousCategorie::factory()->create(['categorie_id' => $cat->id, 'nom' => 'Salle']);

    foreach ([$op1, $op2] as $op) {
        $d = Transaction::factory()->asDepense()->create(['date' => '2025-10-01', 'saisi_par' => $this->user->id]);
        $d->lignes()->forceDelete();
        TransactionLigne::factory()->create(['transaction_id' => $d->id, 'sous_categorie_id' => $sc->id, 'operation_id' => $op->id, 'seance' => 1, 'montant' => 100.00]);
    }

    Livewire::test(RapportSeances::class)
        ->set('selectedOperationIds', [$op1->id, $op2->id])
        ->assertSee('200,00'); // séance 1 agrégée
});
