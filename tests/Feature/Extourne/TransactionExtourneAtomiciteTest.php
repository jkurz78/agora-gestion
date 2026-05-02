<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Events\TransactionExtournee;
use App\Models\CompteBancaire;
use App\Models\Extourne;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;

function atomActAsComptable(): User
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    return $user;
}

function atomCreateRecette(StatutReglement $statut, ?CompteBancaire $compte = null): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation X',
        'montant_total' => 80,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
        'compte_id' => $compte?->id ?? CompteBancaire::factory()->create()->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 80,
    ]);

    return $tx;
}

test('event listener throw — cas Recu : rollback complet', function (): void {
    atomActAsComptable();
    $origine = atomCreateRecette(StatutReglement::Recu);

    $txCountAvant = Transaction::query()->count();
    $ligneCountAvant = TransactionLigne::query()->count();

    Event::listen(TransactionExtournee::class, function (): void {
        throw new RuntimeException('boom');
    });

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine)))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Extourne::withTrashed()->count())->toBe(0);
    expect($origine->fresh()->extournee_at)->toBeNull();
    expect(Transaction::query()->count())->toBe($txCountAvant);
    expect(TransactionLigne::query()->count())->toBe($ligneCountAvant);
});

test('event listener throw — cas EnAttente : rollback complet (extourne + lettrage + statuts)', function (): void {
    atomActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = atomCreateRecette(StatutReglement::EnAttente, $compte);

    $txCountAvant = Transaction::query()->count();
    $rapproCountAvant = RapprochementBancaire::query()->count();

    Event::listen(TransactionExtournee::class, function (): void {
        throw new RuntimeException('boom');
    });

    expect(fn () => app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine)))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Extourne::withTrashed()->count())->toBe(0);

    $origineFresh = $origine->fresh();
    expect($origineFresh->extournee_at)->toBeNull();
    expect($origineFresh->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($origineFresh->rapprochement_id)->toBeNull();

    expect(Transaction::query()->count())->toBe($txCountAvant);
    expect(
        RapprochementBancaire::query()
            ->where('type', TypeRapprochement::Lettrage)
            ->count()
    )->toBe(0);
    expect(RapprochementBancaire::query()->count())->toBe($rapproCountAvant);
});
