<?php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\AnimateurManager;
use App\Models\Categorie;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->categorie = Categorie::factory()->depense()->create();
    $this->sousCategorie = SousCategorie::factory()->create([
        'categorie_id' => $this->categorie->id,
    ]);

    $this->operation = Operation::factory()->withSeances(3)->create();

    // Create séances for the operation
    for ($i = 1; $i <= 3; $i++) {
        Seance::create([
            'operation_id' => $this->operation->id,
            'numero' => $i,
            'date' => now()->addDays($i),
        ]);
    }

    $this->tiers = Tiers::factory()->pourDepenses()->create([
        'nom' => 'Durand',
        'prenom' => 'Sophie',
    ]);
});

it('renders with empty matrix when no depenses exist', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->assertOk()
        ->assertSeeHtml("Aucune facture d'animateur");
});

it('displays animateur from existing depense transaction', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'tiers_id' => $this->tiers->id,
        'date' => now(),
        'montant_total' => 100.00,
    ]);

    // Remove auto-generated lines and create our own linked to the operation
    TransactionLigne::where('transaction_id', $transaction->id)->delete();
    TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => $this->operation->id,
        'seance' => 1,
        'montant' => 100.00,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->assertSee('DURAND')
        ->assertSee('Sophie');
});

it('opens create modal with pre-filled tiers and seance', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->tiers->id, 2)
        ->assertSet('showModal', true)
        ->assertSet('isEditing', false)
        ->assertSet('modalTiersId', $this->tiers->id)
        ->assertSet('modalLignes.0.seance', 2)
        ->assertSet('modalLignes.0.operation_id', $this->operation->id);
});

it('creates a depense transaction from modal', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->tiers->id, 1)
        ->set('modalDate', now()->format('Y-m-d'))
        ->set('modalReference', 'FAC-2026-001')
        ->set('modalLignes.0.sous_categorie_id', $this->sousCategorie->id)
        ->set('modalLignes.0.montant', '150.00')
        ->call('saveTransaction')
        ->assertSet('showModal', false);

    $transaction = Transaction::where('tiers_id', $this->tiers->id)
        ->where('type', TypeTransaction::Depense)
        ->latest('id')
        ->first();

    expect($transaction)->not->toBeNull();
    expect((float) $transaction->montant_total)->toBe(150.00);
    expect($transaction->reference)->toBe('FAC-2026-001');

    $ligne = TransactionLigne::where('transaction_id', $transaction->id)
        ->where('operation_id', $this->operation->id)
        ->first();

    expect($ligne)->not->toBeNull();
    expect($ligne->seance)->toBe(1);
    expect((float) $ligne->montant)->toBe(150.00);
});

it('validates required fields on save', function () {
    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->tiers->id, null)
        ->set('modalDate', '')
        ->set('modalReference', '')
        ->set('modalLignes.0.sous_categorie_id', null)
        ->set('modalLignes.0.montant', '')
        ->call('saveTransaction')
        ->assertHasErrors(['modalDate', 'modalReference', 'modalLignes.0.sous_categorie_id', 'modalLignes.0.montant']);
});

it('opens edit modal with existing transaction data', function () {
    $transaction = Transaction::factory()->asDepense()->create([
        'tiers_id' => $this->tiers->id,
        'date' => '2026-03-15',
        'reference' => 'REF-EDIT',
        'montant_total' => 200.00,
    ]);

    // Remove auto-generated lines and create our own
    TransactionLigne::where('transaction_id', $transaction->id)->delete();
    $ligne = TransactionLigne::factory()->create([
        'transaction_id' => $transaction->id,
        'sous_categorie_id' => $this->sousCategorie->id,
        'operation_id' => $this->operation->id,
        'seance' => 2,
        'montant' => 200.00,
    ]);

    Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openEditModal', [$transaction->id])
        ->assertSet('showModal', true)
        ->assertSet('isEditing', true)
        ->assertSet('editingTransactionId', $transaction->id)
        ->assertSet('modalTiersId', $this->tiers->id)
        ->assertSet('modalDate', '2026-03-15')
        ->assertSet('modalReference', 'REF-EDIT')
        ->assertSet('modalLignes.0.sous_categorie_id', $this->sousCategorie->id)
        ->assertSet('modalLignes.0.seance', 2)
        ->assertSet('modalLignes.0.id', $ligne->id);
});

it('adds and removes modal lines', function () {
    $component = Livewire::test(AnimateurManager::class, ['operation' => $this->operation])
        ->call('openCreateModal', $this->tiers->id, 1);

    // Starts with 1 line
    expect($component->get('modalLignes'))->toHaveCount(1);

    // Add a line
    $component->call('addModalLigne');
    expect($component->get('modalLignes'))->toHaveCount(2);

    // Add another
    $component->call('addModalLigne');
    expect($component->get('modalLignes'))->toHaveCount(3);

    // Remove middle line (index 1)
    $component->call('removeModalLigne', 1);
    expect($component->get('modalLignes'))->toHaveCount(2);

    // Cannot remove below 1 line
    $component->call('removeModalLigne', 0);
    expect($component->get('modalLignes'))->toHaveCount(1);

    $component->call('removeModalLigne', 0);
    expect($component->get('modalLignes'))->toHaveCount(1);
});
