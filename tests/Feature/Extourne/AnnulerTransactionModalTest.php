<?php

declare(strict_types=1);

use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\Extournes\AnnulerTransactionModal;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function modalActAs(RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    return $user;
}

function modalCreateRecette(StatutReglement $statut = StatutReglement::Recu): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont',
        'montant_total' => 80,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 80,
    ]);

    return $tx;
}

test('open prefills libellé date mode_paiement from origine', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', true)
        ->assertSet('libelle', 'Annulation - Cotisation Mr Dupont')
        ->assertSet('modePaiement', ModePaiement::Cheque->value)
        ->assertSet('date', now()->toDateString());
});

test('submit appelle le service et dispatch success', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->call('submit')
        ->assertDispatched('extourne:success')
        ->assertSet('isOpen', false);

    $extourne = Extourne::query()->where('transaction_origine_id', $origine->id)->first();
    expect($extourne)->not->toBeNull();
    expect($extourne->extourne->libelle)->toBe('Annulation - Cotisation Mr Dupont');
});

test('submit applique override libellé', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->set('libelle', 'Remboursement geste commercial')
        ->call('submit');

    $extourne = Extourne::query()->where('transaction_origine_id', $origine->id)->first();
    expect($extourne->extourne->libelle)->toBe('Remboursement geste commercial');
});

test('submit applique override mode_paiement', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->set('modePaiement', ModePaiement::Virement->value)
        ->call('submit');

    $extourne = Extourne::query()->where('transaction_origine_id', $origine->id)->first();
    expect($extourne->extourne->mode_paiement)->toBe(ModePaiement::Virement);
});

test('submit applique notes', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->set('notes', 'Désistement séance 14/03 (santé)')
        ->call('submit');

    $extourne = Extourne::query()->where('transaction_origine_id', $origine->id)->first();
    expect($extourne->extourne->notes)->toBe('Désistement séance 14/03 (santé)');
});

test('Gestionnaire — submit ne crée pas d extourne (AuthorizationException relayée en flash)', function (): void {
    modalActAs(RoleAssociation::Gestionnaire);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->call('submit')
        ->assertDispatched('extourne:error');

    expect(Extourne::query()->count())->toBe(0);
});

test('vue rend une modale Bootstrap (class=modal data-bs-* sans confirm natif)', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    $component = Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id);

    $html = $component->html();
    expect($html)->toContain('class="modal fade');
    expect($html)->toContain('data-bs-dismiss="modal"');
    expect($html)->not->toContain('window.confirm');
});

test('close réinitialise l état et ne soumet rien', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->call('close')
        ->assertSet('isOpen', false)
        ->assertSet('transactionId', null);

    expect(Extourne::query()->count())->toBe(0);
});

test('open avec transaction non extournable affiche message d erreur', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();
    $origine->update(['extournee_at' => now()]);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', false)
        ->assertDispatched('extourne:error');
});
