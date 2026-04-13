<?php

declare(strict_types=1);

use App\Livewire\TransactionUniverselle;
use App\Models\CompteBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('se rend sans erreur', function () {
    Livewire::test(TransactionUniverselle::class)
        ->assertStatus(200);
});

it('accepte les props verrouillées en mount', function () {
    $compte = CompteBancaire::factory()->create();
    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('supprime une recette via deleteRow', function () {
    $recette = Transaction::factory()->asRecette()->create(['date' => '2025-10-01']);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'recette', $recette->id);
    $this->assertSoftDeleted('transactions', ['id' => $recette->id]);
});

it('ne supprime pas une transaction pointée', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'date' => '2025-10-01',
        'statut_reglement' => 'pointe',
    ]);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'depense', $tx->id);
    $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'deleted_at' => null]);
});

it('la page /transactions rend TransactionUniverselle avec lockedTypes depense+recette', function () {
    $this->get('/comptabilite/transactions')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['depense', 'recette']])
        ->assertSet('lockedTypes', ['depense', 'recette']);
});

it('la page /comptes-bancaires/{id}/transactions rend TransactionUniverselle avec compteId', function () {
    $compte = CompteBancaire::factory()->create();
    $this->get("/banques/comptes/{$compte->id}/transactions")
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('la page /tiers/{id}/transactions rend TransactionUniverselle avec tiersId', function () {
    $tiers = Tiers::factory()->create();
    $this->get("/tiers/{$tiers->id}/transactions")
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);
});

it('la page /dons rend TransactionUniverselle avec sousCategorieFilter pour_dons', function () {
    $this->get('/tiers/dons')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['sousCategorieFilter' => 'pour_dons'])
        ->assertSet('sousCategorieFilter', 'pour_dons');
});

it('la page /cotisations rend TransactionUniverselle avec sousCategorieFilter pour_cotisations', function () {
    $this->get('/tiers/cotisations')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['sousCategorieFilter' => 'pour_cotisations'])
        ->assertSet('sousCategorieFilter', 'pour_cotisations');
});
