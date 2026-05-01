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

test('extourner recette EnAttente — crée extourne + lettrage automatique', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->rapprochement_lettrage_id)->not->toBeNull();
    $lettrage = $extourne->lettrage;

    expect($lettrage)->toBeInstanceOf(RapprochementBancaire::class);
    expect($lettrage->type)->toBe(TypeRapprochement::Lettrage);
    expect($lettrage->statut)->toBe(StatutRapprochement::Verrouille);
    expect($lettrage->compte_id)->toBe($compte->id);
    expect((float) $lettrage->solde_ouverture)->toBe((float) $lettrage->solde_fin);
});

test('lettrage met origine et extourne au statut Pointe', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $miroir = $extourne->extourne;
    $lettrage = $extourne->lettrage;

    $origine->refresh();
    $miroir->refresh();

    expect($origine->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($miroir->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($origine->rapprochement_id)->toBe($lettrage->id);
    expect($miroir->rapprochement_id)->toBe($lettrage->id);
});

test('lettrage contient exactement 2 transactions appariées', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $lettrage = $extourne->lettrage;
    $txDansLettrage = $lettrage->transactions()->get();

    expect($txDansLettrage)->toHaveCount(2);
    $ids = $txDansLettrage->pluck('id')->all();
    expect($ids)->toContain($origine->id);
    expect($ids)->toContain($extourne->extourne->id);
});

test('solde_ouverture du lettrage est égal au solde du dernier rapprochement bancaire verrouillé', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();

    // Rapprochement bancaire préalable verrouillé fixant solde 500 €
    RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'solde_ouverture' => 0,
        'solde_fin' => 500,
        'date_fin' => now()->subDay()->toDateString(),
    ]);

    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect((float) $extourne->lettrage->solde_ouverture)->toBe(500.0);
    expect((float) $extourne->lettrage->solde_fin)->toBe(500.0);
});

test('solde_ouverture du lettrage est 0 si aucun rapprochement bancaire préalable', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect((float) $extourne->lettrage->solde_ouverture)->toBe(0.0);
    expect((float) $extourne->lettrage->solde_fin)->toBe(0.0);
});

test('lettrage est créé directement Verrouille (pas de phase EnCours pour ce type)', function (): void {
    enAttenteActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = enAttenteCreateRecette($compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    expect($extourne->lettrage->statut)->toBe(StatutRapprochement::Verrouille);
    expect($extourne->lettrage->verrouille_at)->not->toBeNull();
});
