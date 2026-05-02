<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Livewire\TransactionUniverselle;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function indicActAsComptable(): User
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

function indicCreateRecette(?CompteBancaire $compte = null): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation Mr Dupont',
        'montant_total' => 80,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Recu,
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

test('badge "annulée" affiché sur la ligne d origine extournée', function (): void {
    indicActAsComptable();
    $origine = indicCreateRecette();
    app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $component = Livewire::test(TransactionUniverselle::class, [
        'compteId' => $origine->compte_id,
        'pageTitle' => 'Test',
    ]);

    $html = $component->html();
    expect($html)->toContain('annulée');
});

test('ligne d extourne rendue en italique avec préfixe Annulation', function (): void {
    indicActAsComptable();
    $origine = indicCreateRecette();
    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine, ExtournePayload::fromOrigine($origine));

    $component = Livewire::test(TransactionUniverselle::class, [
        'compteId' => $origine->compte_id,
        'pageTitle' => 'Test',
    ]);

    $html = $component->html();
    // L'extourne (-80 €) apparaît avec libellé "Annulation - …"
    expect($html)->toContain('Annulation - Cotisation Mr Dupont');
    // Et la classe italique appliquée à la ligne extourne
    expect($html)->toContain('fst-italic');
});

test('N+1 — 25 transactions extournées : nombre de queries borné', function (): void {
    indicActAsComptable();
    $compte = CompteBancaire::factory()->create();

    for ($i = 0; $i < 25; $i++) {
        $origine = indicCreateRecette($compte);
        app(TransactionExtourneService::class)
            ->extourner($origine, ExtournePayload::fromOrigine($origine));
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(TransactionUniverselle::class, [
        'compteId' => $compte->id,
        'pageTitle' => 'Test',
    ]);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Breakdown attendu : count + paginate + lookup extournes (≤ 4-5 queries
    // au total grâce au join SQL inline pour is_extournee / is_extourne_miroir).
    // Threshold large 30 = absorbe les queries auth/tenant/composant Livewire.
    expect(count($queries))->toBeLessThanOrEqual(30,
        'Trop de queries — N+1 suspecté. Total: '.count($queries));
});
