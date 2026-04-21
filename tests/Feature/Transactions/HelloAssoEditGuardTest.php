<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compteHelloasso = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'HelloAsso',
        'saisie_automatisee' => true,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('marks the transaction as locked by HelloAsso when edit() opens it', function () {
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteHelloasso->id,
        'helloasso_order_id' => 12345,
        'date' => '2025-10-01',
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSet('isLockedByHelloAsso', true);
});

it('does NOT mark normal transactions as locked by HelloAsso', function () {
    $compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte courant',
    ]);
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $compte->id,
        'helloasso_order_id' => null,
        'date' => '2025-10-01',
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSet('isLockedByHelloAsso', false);
});

it('rejects save when a source field (compte_id) is modified on HelloAsso transaction', function () {
    $autreCompte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'nom' => 'Compte courant',
    ]);
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteHelloasso->id,
        'helloasso_order_id' => 12345,
        'date' => '2025-10-01',
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('compte_id', $autreCompte->id)
        ->call('save')
        ->assertHasErrors(['compte_id']);

    expect((int) $tx->fresh()->compte_id)->toBe((int) $this->compteHelloasso->id);
});

it('allows editing notes on HelloAsso transaction', function () {
    $tx = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteHelloasso->id,
        'helloasso_order_id' => 12345,
        'notes' => 'ancienne note',
        'date' => '2025-10-01',
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->set('notes', 'nouvelle note')
        ->call('save')
        ->assertHasNoErrors();

    expect($tx->fresh()->notes)->toBe('nouvelle note');
});
