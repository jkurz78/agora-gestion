<?php

declare(strict_types=1);

use App\DataTransferObjects\ExtournePayload;
use App\Enums\ModePaiement;
use App\Enums\RoleAssociation;
use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Exceptions\ExerciceCloturedException;
use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\TransactionExtourneService;
use App\Tenant\TenantContext;
use Carbon\Carbon;

/*
 * Une extourne datée sur un exercice clôturé doit être refusée, comme toute
 * autre écriture. Le service construit sa transaction miroir à partir d'une
 * date fournie par l'utilisateur, sans passer par TransactionService.
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 : exercice affiché 2025
 * (2025-09-01 → 2026-08-31). L'exercice 2024 va du 2024-09-01 au 2025-08-31.
 */

function extourneClotureRecette(): Transaction
{
    $user = User::factory()->create();
    $user->associations()->attach(TenantContext::currentId(), [
        'role' => RoleAssociation::Comptable->value,
        'joined_at' => now(),
    ]);
    $user->update(['derniere_association_id' => TenantContext::currentId()]);
    auth()->login($user);

    $tx = Transaction::factory()->create([
        'type' => TypeTransaction::Recette,
        'libelle' => 'Cotisation à extourner',
        'montant_total' => 80,
        'date' => '2025-11-15',
        'mode_paiement' => ModePaiement::Cheque,
        'statut_reglement' => StatutReglement::Pointe,
        'compte_id' => CompteBancaire::factory()->create()->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 80,
        'debit' => 0.0,
        'credit' => 80,
    ]);

    return $tx;
}

it('refuse une extourne datée sur un exercice clôturé', function () {
    Exercice::create([
        'association_id' => TenantContext::currentId(),
        'annee' => 2024,
        'statut' => StatutExercice::Cloture,
    ]);
    $origine = extourneClotureRecette();

    $avant = Transaction::count();

    try {
        app(TransactionExtourneService::class)->extourner(
            $origine,
            ExtournePayload::fromOrigine($origine, ['date' => Carbon::parse('2025-01-15')]),
        );
        $refusee = false;
    } catch (ExerciceCloturedException) {
        $refusee = true;
    }

    expect($refusee)->toBeTrue()
        ->and(Transaction::count())->toBe($avant);
});
