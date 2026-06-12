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
        ->assertSet('errorMessage', fn ($v) => $v !== null);

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

// ─── Chemin suppression (Dû / En main) ────────────────────────────

test('open avec EnAttente → mode suppression', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::EnAttente);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', true)
        ->assertSet('mode', 'suppression')
        ->assertSet('motif', '');
});

test('open avec EnMain → mode suppression', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::EnMain);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', true)
        ->assertSet('mode', 'suppression');
});

test('open avec Recu → mode extourne', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::Recu);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', true)
        ->assertSet('mode', 'extourne')
        ->assertSet('libelle', 'Annulation - Cotisation Mr Dupont');
});

test('submit suppression avec motif → soft-delete la transaction', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::EnAttente);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->set('motif', 'Inscription annulée par le participant')
        ->call('submit')
        ->assertDispatched('extourne:success')
        ->assertSet('isOpen', false);

    // La TX est soft-deleted
    expect(Transaction::find($origine->id))->toBeNull();

    // La TX existe encore en withTrashed
    $tx = Transaction::withTrashed()->find($origine->id);
    expect($tx)->not->toBeNull();
    expect($tx->motif_suppression)->toBe('Inscription annulée par le participant');
    expect($tx->supprime_par)->not->toBeNull();
    expect($tx->deleted_at)->not->toBeNull();
});

test('submit suppression sans motif → erreur validation', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::EnAttente);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->set('motif', '')
        ->call('submit')
        ->assertHasErrors(['motif' => 'required']);

    // TX toujours là
    expect(Transaction::find($origine->id))->not->toBeNull();
});

test('suppression affiche le bon wording dans la modale', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::EnAttente);

    $html = Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->html();

    expect($html)->toContain('pas encore atteint la banque');
    expect($html)->toContain('annulation');
    expect($html)->not->toContain('extourne-libelle');
});

test('extourne affiche le bon wording dans la modale', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette(StatutReglement::Recu);

    $html = Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->html();

    expect($html)->toContain('en banque');
    expect($html)->toContain('extourne');
    expect($html)->not->toContain('pas encore atteint la banque');
});

// ─── Guards existants ──────────────────────────────────────────────

test('open avec transaction non extournable affiche message d erreur', function (): void {
    modalActAs(RoleAssociation::Comptable);
    $origine = modalCreateRecette();
    $origine->update(['extournee_at' => now()]);

    Livewire::test(AnnulerTransactionModal::class)
        ->call('open', $origine->id)
        ->assertSet('isOpen', false)
        ->assertSet('errorMessage', 'Cette transaction ne peut pas être annulée.');
});
