<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
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

function enAttenteActAsComptable(): User
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

function enAttenteCreateRecette(CompteBancaire $compte, float $montant = 80.0): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont mars',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::EnAttente,
        'compte_id' => $compte->id,
    ]);

    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    return $tx;
}

test('extourner recette EnAttente — crée extourne sans lettrage automatique', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    // Pas de lettrage automatique (rapprochement_lettrage_id = null)
    expect($extourne->rapprochement_lettrage_id)->toBeNull();

    // Aucun rapprochement de type Lettrage créé
    expect(RapprochementBancaire::where('type', TypeRapprochement::Lettrage)->count())->toBe(0);
});

test('origine et extourne passent au statut Pointe', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $miroir = $extourne->extourne;

    $origine->refresh();
    $miroir->refresh();

    expect($origine->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($miroir->statut_reglement)->toBe(StatutReglement::Pointe);
});

test('miroir est créé avec montant négatif et type_ecriture extourne', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $miroir = $extourne->extourne;
    expect($miroir)->not->toBeNull();
    expect((float) $miroir->montant_total)->toBe(-80.0);
    expect($miroir->type_ecriture)->toBe('extourne');
});

test('origine porte extournee_at après extourne', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $origine->refresh();
    expect($origine->extournee_at)->not->toBeNull();
});

test('rapprochement_id reste null sur les deux transactions (pas de lettrage)', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $miroir = $extourne->extourne;
    $origine->refresh();

    expect($origine->rapprochement_id)->toBeNull();
    expect($miroir->rapprochement_id)->toBeNull();
});
