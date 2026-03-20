<?php

declare(strict_types=1);

use App\Livewire\TransactionUniverselle;
use App\Models\{Don, Transaction, User};
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('se rend sans erreur', function () {
    Livewire::test(TransactionUniverselle::class)
        ->assertStatus(200);
});

it('accepte les props verrouillées en mount', function () {
    $compte = \App\Models\CompteBancaire::factory()->create();
    Livewire::test(TransactionUniverselle::class, ['compteId' => $compte->id])
        ->assertSet('compteId', $compte->id);
});

it('supprime un don via deleteRow', function () {
    $don = Don::factory()->create(['date' => '2025-10-01']);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'don', $don->id);
    $this->assertSoftDeleted('dons', ['id' => $don->id]);
});

it('ne supprime pas une transaction pointée', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'date'   => '2025-10-01',
        'pointe' => true,
    ]);
    Livewire::test(TransactionUniverselle::class)
        ->call('deleteRow', 'depense', $tx->id);
    $this->assertDatabaseHas('transactions', ['id' => $tx->id, 'deleted_at' => null]);
});
