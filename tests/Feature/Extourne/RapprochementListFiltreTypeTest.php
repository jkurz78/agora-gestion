<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutRapprochement;
use App\Enums\StatutReglement;
use App\Enums\TypeRapprochement;
use App\Enums\TypeTransaction;
use App\Livewire\RapprochementList;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\RapprochementBancaireService;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Livewire\Livewire;

function rapprochementActAsComptable(): User
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

function rapprochementCreateRecette(CompteBancaire $compte, StatutReglement $statut, float $montant = 80.0): Transaction
{
    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'X',
        'montant_total' => $montant,
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => $statut,
        'compte_id' => $compte->id,
        'date' => now()->toDateString(),
    ]);
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'sous_categorie_id' => null,
        'montant' => $montant,
    ]);

    return $tx;
}

test('filtre par défaut "Bancaire" — n affiche pas les lettrages', function (): void {
    rapprochementActAsComptable();
    $compte = CompteBancaire::factory()->create();

    // 2 rapprochements bancaires
    RapprochementBancaire::factory()->count(2)->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
    ]);
    // 1 lettrage
    RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Lettrage,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $compte->id)
        ->assertSet('filterType', 'bancaire')
        ->assertViewHas('rapprochements', fn ($p) => $p->total() === 2);
});

test('filtre "Lettrage" — n affiche que les lettrages', function (): void {
    rapprochementActAsComptable();
    $compte = CompteBancaire::factory()->create();

    RapprochementBancaire::factory()->count(2)->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
    ]);
    RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Lettrage,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $compte->id)
        ->set('filterType', 'lettrage')
        ->assertViewHas('rapprochements', fn ($p) => $p->total() === 1);
});

test('filtre "Tous" — affiche les deux types', function (): void {
    rapprochementActAsComptable();
    $compte = CompteBancaire::factory()->create();

    RapprochementBancaire::factory()->count(2)->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
    ]);
    RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Lettrage,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $compte->id)
        ->set('filterType', 'tous')
        ->assertViewHas('rapprochements', fn ($p) => $p->total() === 3);
});

test('colonne Type rendue dans la vue', function (): void {
    rapprochementActAsComptable();
    $compte = CompteBancaire::factory()->create();
    RapprochementBancaire::factory()->create(['compte_id' => $compte->id]);

    $component = Livewire::test(RapprochementList::class)
        ->set('compte_id', $compte->id);

    $html = $component->html();
    expect($html)->toContain('Bancaire');
});

/**
 * BDD Scénario 3 — "L'extourne d'une recette encaissée se solde par pointage banque ordinaire"
 *
 * Setup : tx recette 80€ Recu, déjà pointée à R1 verrouillé.
 * Action : extourne via service → extourne -80€ EnAttente sans lettrage.
 * Suite : créer R2 (type Bancaire), pointer extourne dans R2 via le mécanisme
 *   existant (set rapprochement_id), verrouiller R2.
 * Assertion : extourne passe à Pointe, origine reste rattachée à R1 inchangée.
 */
test('BDD scénario 3 — extourne recette encaissée pointable dans rapprochement bancaire ordinaire', function (): void {
    rapprochementActAsComptable();
    $compte = CompteBancaire::factory()->create();

    // R1 verrouillé bancaire
    $r1 = RapprochementBancaire::factory()->create([
        'compte_id' => $compte->id,
        'type' => TypeRapprochement::Bancaire,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'date_fin' => now()->subDay()->toDateString(),
        'solde_fin' => 500,
    ]);

    // Origine : recette Recu pointée à R1
    $origine = rapprochementCreateRecette($compte, StatutReglement::Pointe);
    $origine->update(['rapprochement_id' => $r1->id]);

    // Extourner — pas de lettrage car cas Pointe verrouillé
    $extourne = app(TransactionExtourneService::class)
        ->extourner($origine->fresh(), ExtournePayload::fromOrigine($origine->fresh()));

    expect($extourne->rapprochement_lettrage_id)->toBeNull();
    expect($extourne->extourne->statut_reglement)->toBe(StatutReglement::EnAttente);

    // R2 EnCours bancaire, pointer l'extourne et verrouiller
    $r2 = app(RapprochementBancaireService::class)->create($compte, now()->toDateString(), 420);
    $extourne->extourne->update([
        'rapprochement_id' => $r2->id,
        'statut_reglement' => StatutReglement::Pointe,
    ]);
    app(RapprochementBancaireService::class)->verrouiller($r2);

    // Asserts
    $miroirFresh = $extourne->extourne->fresh();
    expect($miroirFresh->statut_reglement)->toBe(StatutReglement::Pointe);
    expect($miroirFresh->rapprochement_id)->toBe($r2->id);

    $origineFresh = $origine->fresh();
    expect($origineFresh->rapprochement_id)->toBe($r1->id);
    expect($origineFresh->statut_reglement)->toBe(StatutReglement::Pointe);
});
