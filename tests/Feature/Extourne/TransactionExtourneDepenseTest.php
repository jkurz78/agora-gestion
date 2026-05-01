<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;

function depenseActAsComptable(): User
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

function depenseCreate(StatutReglement $statut, ?CompteBancaire $compte = null): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Depense,
        'libelle' => 'Achat fournitures Mr Fournisseur',
        'montant_total' => 80,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
        'compte_id' => $compte?->id ?? CompteBancaire::factory()->create()->id,
        'date' => now()->toDateString(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => 80,
    ]);

    return $tx;
}

test('extourner dépense Recu — crée extourne EnAttente sans lettrage', function (): void {
    depenseActAsComptable();
    $origine = depenseCreate(StatutReglement::Recu);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->rapprochement_lettrage_id)->toBeNull();

    $miroir = $extourne->extourne;
    expect($miroir->type)->toBe(TypeTransaction::Depense);
    expect((float) $miroir->montant_total)->toBe(-80.0);
    expect($miroir->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect($miroir->libelle)->toBe('Annulation - Achat fournitures Mr Fournisseur');

    $origine->refresh();
    expect($origine->statut_reglement)->toBe(StatutReglement::Recu);
    expect($origine->extournee_at)->not->toBeNull();
});

test('extourner dépense EnAttente — crée extourne + lettrage automatique', function (): void {
    depenseActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = depenseCreate(StatutReglement::EnAttente, $compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->rapprochement_lettrage_id)->not->toBeNull();
    expect($extourne->lettrage)->toBeInstanceOf(RapprochementBancaire::class);
    expect($extourne->lettrage->type)->toBe(TypeRapprochement::Lettrage);
    expect($extourne->lettrage->statut)->toBe(StatutRapprochement::Verrouille);

    $origine->refresh();
    expect($origine->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($origine->rapprochement_id)->toBe($extourne->lettrage->id);

    $miroir = $extourne->extourne;
    $miroir->refresh();
    expect($miroir->statut_reglement)->toBe(StatutReglement::Pointe);
    expect((float) $miroir->montant_total)->toBe(-80.0);
});

test('extourner dépense Pointe verrouillée — crée extourne EnAttente sans lettrage', function (): void {
    depenseActAsComptable();
    $rapprochement = RapprochementBancaire::factory()->create();
    $origine = depenseCreate(StatutReglement::Pointe);
    $origine->update([
        'rapprochement_id' => $rapprochement->id,
        'compte_id' => $rapprochement->compte_id,
    ]);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine->fresh(), ExtournePayload::fromOrigine($origine->fresh()));

    expect($extourne->rapprochement_lettrage_id)->toBeNull();
    expect($extourne->extourne->statut_reglement)->toBe(StatutReglement::EnAttente);

    $origine->refresh();
    expect($origine->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($origine->rapprochement_id)->toBe($rapprochement->id);
});

test('payload mode_paiement override est appliqué sur l extourne dépense', function (): void {
    depenseActAsComptable();
    $origine = depenseCreate(StatutReglement::Recu);
    $payload = ExtournePayload::fromOrigine($origine, ['mode_paiement' => ModePaiement::Virement]);

    $extourne = app(TransactionExtourneService::class)->extourner($origine, $payload);

    expect($extourne->extourne->mode_paiement)->toBe(ModePaiement::Virement);
});
