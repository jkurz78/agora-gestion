<?php

declare(strict_types=1);

use App\Livewire\AdherentList;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->cotSc = SousCategorie::factory()->create(['pour_cotisations' => true]);
});

it('affiche bi-check-lg Bootstrap Icon pour un membre avec cotisation pointée', function () {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'pointe' => true,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->cotSc->id,
        'montant' => 30.00,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-check-lg text-success');
});

it('n\'affiche pas le caractère unicode ✓', function () {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->asRecette()->create([
        'tiers_id' => $tiers->id,
        'pointe' => true,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->cotSc->id,
        'montant' => 30.00,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertDontSee('✓');
});

it('affiche un bouton bi-clock-history lié aux transactions du membre', function () {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->asRecette()->create(['tiers_id' => $tiers->id]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->cotSc->id,
        'montant' => 30.00,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('bi bi-clock-history')
        ->assertSeeHtml('href="'.route('compta.tiers.transactions', $tiers->id).'"');
});

it('les boutons d\'action ont la classe btn-sm sans style inline de padding', function () {
    $tiers = Tiers::factory()->create();
    $tx = Transaction::factory()->asRecette()->create(['tiers_id' => $tiers->id]);
    $tx->lignes()->forceDelete();
    TransactionLigne::factory()->create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => $this->cotSc->id,
        'montant' => 30.00,
    ]);

    Livewire::test(AdherentList::class)
        ->set('filtre', 'tous')
        ->assertSeeHtml('btn btn-sm')
        ->assertDontSeeHtml('padding:.15rem');
});
