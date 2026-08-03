<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
});

afterEach(fn () => TenantContext::clear());

test('showNewForm recette pose sensTresorerie = recette', function () {
    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'recette');

    expect($component->get('sensTresorerie'))->toBe('recette');
});

test('showNewForm depense pose sensTresorerie = depense', function () {
    $component = Livewire::test(TransactionForm::class)
        ->call('showNewForm', 'depense');

    expect($component->get('sensTresorerie'))->toBe('depense');
});

test('edit recette normale pose sensTresorerie = recette', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'normale',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('recette');
    expect($component->get('type'))->toBe('recette');
});

test('edit miroir extourne de recette pose sensTresorerie = depense', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('depense');
    expect($component->get('type'))->toBe('recette');
    expect($component->get('isExtourneMiroir'))->toBeTrue();
});

test('edit miroir extourne de depense pose sensTresorerie = recette', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    expect($component->get('sensTresorerie'))->toBe('recette');
    expect($component->get('type'))->toBe('depense');
    expect($component->get('isExtourneMiroir'))->toBeTrue();
});

test('blade affiche badge Dépense pour miroir extourne de recette', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    $component->assertSee('Dépense')
        ->assertSee('Remboursement (extourne)')
        ->assertSeeHtml('badge bg-danger');
});

test('blade affiche badge Recette pour miroir extourne de dépense', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    $component = Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id);

    $component->assertSee('Recette')
        ->assertSee('Remboursement (extourne)')
        ->assertSeeHtml('badge bg-success');
});

test('blade affiche "Paiement effectué ?" pour miroir extourne de recette (sens=depense)', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSee('Paiement effectué');
});

test('blade affiche "Paiement déjà reçu ?" pour miroir extourne de dépense (sens=recette)', function () {
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'type_ecriture' => 'extourne',
        'compte_id' => $this->compte->id,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $tx->id)
        ->assertSee('Paiement déjà reçu');
});

test('save miroir extourne de recette met à jour mode_paiement et notes sans blocage gt:0', function () {
    $compteVentilation = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '706M',
        'intitule' => 'Prestations',
        'classe' => 7,
        'actif' => true,
    ]);
    $compte2 = CompteBancaire::factory()->create(['association_id' => $this->association->id]);

    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Recette,
        'type_ecriture' => 'extourne',
        'montant_total' => -150.00,
        'mode_paiement' => ModePaiement::Cheque,
        'compte_id' => $this->compte->id,
        'statut_reglement' => StatutReglement::EnAttente,
    ]);
    $miroir->lignes()->forceDelete();
    // Ligne miroir signée (brèche du signe) : credit négatif, XOR satisfait.
    TransactionLigne::create([
        'transaction_id' => $miroir->id,
        'compte_id' => $compteVentilation->id,
        'montant' => -150.00,
        'debit' => 0,
        'credit' => -150.00,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $miroir->id)
        ->set('mode_paiement', ModePaiement::Virement->value)
        ->set('compte_id', $compte2->id)
        ->set('notes', 'Remboursement par virement')
        ->set('paiementRecu', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    $miroir->refresh();
    expect($miroir->mode_paiement)->toBe(ModePaiement::Virement);
    expect((int) $miroir->compte_id)->toBe((int) $compte2->id);
    expect($miroir->notes)->toBe('Remboursement par virement');
    expect((float) $miroir->montant_total)->toBe(-150.00);
});

test('save miroir extourne de dépense met à jour mode_paiement sans blocage gt:0', function () {
    $compteVentilation = Compte::create([
        'association_id' => $this->association->id,
        'numero_pcg' => '606M',
        'intitule' => 'Fournitures',
        'classe' => 6,
        'actif' => true,
    ]);

    $miroir = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'type_ecriture' => 'extourne',
        'montant_total' => -200.00,
        'mode_paiement' => ModePaiement::Especes,
        'compte_id' => $this->compte->id,
        'statut_reglement' => StatutReglement::EnAttente,
    ]);
    $miroir->lignes()->forceDelete();
    // Ligne miroir signée (brèche du signe) : debit négatif, XOR satisfait.
    TransactionLigne::create([
        'transaction_id' => $miroir->id,
        'compte_id' => $compteVentilation->id,
        'montant' => -200.00,
        'debit' => -200.00,
        'credit' => 0,
    ]);

    Livewire::test(TransactionForm::class)
        ->call('edit', $miroir->id)
        ->set('mode_paiement', ModePaiement::Cheque->value)
        ->set('paiementRecu', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('transaction-saved');

    $miroir->refresh();
    expect($miroir->mode_paiement)->toBe(ModePaiement::Cheque);
    expect((float) $miroir->montant_total)->toBe(-200.00);
});
