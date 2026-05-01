<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\Concerns\MontantValidation;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Services\TransactionUniverselleService;
use App\Tenant\TenantContext;

function creancesActAsComptable(): User
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

function creancesCreateRecette(StatutReglement $statut, CompteBancaire $compte, float $montant = 80.0): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation X',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
        'compte_id' => $compte->id,
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    return $tx;
}

test('extourne EnAttente cas Recu — n apparaît pas dans Créances à recevoir', function (): void {
    creancesActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = creancesCreateRecette(StatutReglement::Recu, $compte);

    // Crée l'extourne — naît EnAttente sans lettrage
    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $miroir = $extourne->extourne;
    expect($miroir->statut_reglement)->toBe(StatutReglement::EnAttente);
    expect((float) $miroir->montant_total)->toBe(-80.0);

    $service = app(TransactionUniverselleService::class);
    $result = $service->paginate(
        compteId: null,
        tiersId: null,
        types: null,
        dateDebut: null,
        dateFin: null,
        searchTiers: null,
        searchLibelle: null,
        searchReference: null,
        searchNumeroPiece: null,
        modePaiement: null,
        statutReglement: 'en_attente',
    );

    $ids = collect($result['paginator']->items())->pluck('id')->all();
    expect($ids)->not->toContain($miroir->id);
});

test('lettrage cas EnAttente — origine et extourne disparaissent des Créances', function (): void {
    creancesActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $origine = creancesCreateRecette(StatutReglement::EnAttente, $compte);

    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $service = app(TransactionUniverselleService::class);
    $result = $service->paginate(
        compteId: null, tiersId: null, types: null,
        dateDebut: null, dateFin: null, searchTiers: null, searchLibelle: null,
        searchReference: null, searchNumeroPiece: null, modePaiement: null,
        statutReglement: 'en_attente',
    );

    $ids = collect($result['paginator']->items())->pluck('id')->all();
    expect($ids)->not->toContain($origine->id);
    expect($ids)->not->toContain($extourne->extourne->id);
});

test('recette positive EnAttente normale apparaît toujours dans Créances (pas de régression S0)', function (): void {
    creancesActAsComptable();
    $compte = CompteBancaire::factory()->create();
    $tx = creancesCreateRecette(StatutReglement::EnAttente, $compte);

    $service = app(TransactionUniverselleService::class);
    $result = $service->paginate(
        compteId: null, tiersId: null, types: null,
        dateDebut: null, dateFin: null, searchTiers: null, searchLibelle: null,
        searchReference: null, searchNumeroPiece: null, modePaiement: null,
        statutReglement: 'en_attente',
    );

    $ids = collect($result['paginator']->items())->pluck('id')->all();
    expect($ids)->toContain($tx->id);
});

/**
 * BDD Scénario 12 (rappel S0) : "Saisie manuelle d'un montant négatif refusée".
 *
 * Le wording exigé par la spec S1 §2 est :
 *   "Le montant doit être positif. L'extourne se fait via le bouton dédié
 *    sur une transaction existante."
 *
 * Ce wording est défini dans App\Livewire\Concerns\MontantValidation::MESSAGE,
 * appliqué par S0 sur tous les formulaires de saisie via la règle gt:0
 * (voir tests/Feature/Audit/RefusesNegatif*Test.php — 9 fichiers couvrant les
 * 9 voies de saisie utilisateur). Pas de duplication de test ici, juste une
 * vérification d'invariant : le wording n'a pas dérivé entre S0 et S1.
 */
test('BDD scénario 12 — wording S0 conforme à la spec S1', function (): void {
    expect(MontantValidation::MESSAGE)
        ->toBe("Le montant doit être positif. L'extourne se fait via le bouton dédié sur une transaction existante.");
    expect(MontantValidation::RULE)->toBe('gt:0');
});
