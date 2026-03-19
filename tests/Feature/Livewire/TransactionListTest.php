<?php

declare(strict_types=1);

use App\Livewire\TransactionList;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    session(['exercice_actif' => 2025]);
});

it('affiche bi-sticky accolé au libellé si la transaction a des notes', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => 'Provision incluse dans le loyer',
        'date'    => '2025-10-01',
    ]);

    Livewire::test(TransactionList::class)
        ->assertSeeHtml('bi bi-sticky')
        ->assertSeeHtml('title="Provision incluse dans le loyer"');
});

it('n\'affiche pas bi-sticky si notes est null', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => null,
        'date'    => '2025-10-01',
    ]);

    Livewire::test(TransactionList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});

it('n\'affiche pas bi-sticky si notes est une chaîne vide', function () {
    Transaction::factory()->asDepense()->create([
        'libelle' => 'Loyer octobre',
        'notes'   => '',
        'date'    => '2025-10-01',
    ]);

    Livewire::test(TransactionList::class)
        ->assertDontSeeHtml('bi bi-sticky');
});
