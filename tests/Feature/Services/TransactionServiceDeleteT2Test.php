<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\Compta\Migrations\SystemeSeeder;
use App\Services\ReglementOperationService;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    Exercice::create(['association_id' => $this->association->id, 'annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);

    SystemeSeeder::seed();
    Config::set('compta.use_partie_double', true);
});

afterEach(function () {
    TenantContext::clear();
});

/**
 * Construit une T1 réglée + sa T2 d'encaissement, lettrées ensemble sur le 411.
 * `trouverT2` repère la T2 via une ligne 411 lettrée partageant le lettrage_code.
 */
function buildT1AvecT2(int $associationId, int $userId): array
{
    $c411 = Compte::where('association_id', $associationId)->where('numero_pcg', '411')->firstOrFail();

    $t1 = Transaction::factory()->create([
        'association_id' => $associationId,
        'type' => TypeTransaction::Recette,
        'date' => '2026-06-30',
        'statut_reglement' => StatutReglement::Recu,
        'helloasso_order_id' => null,
        'rapprochement_id' => null,
        'remise_id' => null,
        'saisi_par' => $userId,
    ]);
    TransactionLigne::create([
        'transaction_id' => $t1->id,
        'compte_id' => $c411->id,
        'debit' => 100.00,
        'credit' => 0,
        'lettrage_code' => 'TESTLET1',
        'montant' => 100.00,
    ]);

    $t2 = Transaction::factory()->create([
        'association_id' => $associationId,
        'type' => TypeTransaction::Recette,
        'date' => '2026-06-30',
        'type_ecriture' => 'normale',
        'helloasso_order_id' => null,
        'saisi_par' => $userId,
    ]);
    TransactionLigne::create([
        'transaction_id' => $t2->id,
        'compte_id' => $c411->id,
        'debit' => 0,
        'credit' => 100.00,
        'lettrage_code' => 'TESTLET1',
        'montant' => 100.00,
    ]);

    return [$t1, $t2];
}

test('delete() d\'une transaction réglée supprime aussi la T2 (pas d\'orphelin)', function () {
    [$t1, $t2] = buildT1AvecT2((int) $this->association->id, (int) $this->user->id);

    // Sanity : la T2 est bien rattachée à la T1 via le lettrage.
    expect(app(ReglementOperationService::class)->trouverT2($t1->fresh())?->id)->toBe((int) $t2->id);

    app(TransactionService::class)->delete($t1->fresh());

    // La T2 ne doit plus exister (force-delete via supprimerT2SiExiste) — pas d'encaissement orphelin.
    expect(Transaction::find($t2->id))->toBeNull();
    expect(Transaction::withTrashed()->find($t2->id))->toBeNull();

    // La T1 est soft-deletée.
    expect(Transaction::find($t1->id))->toBeNull();
    expect(Transaction::withTrashed()->find($t1->id))->not->toBeNull();
});

test('delete() d\'une transaction sans T2 reste un no-op côté T2', function () {
    $t1 = Transaction::factory()->create([
        'association_id' => $this->association->id,
        'type' => TypeTransaction::Depense,
        'date' => '2026-06-30',
        'statut_reglement' => StatutReglement::EnAttente,
        'helloasso_order_id' => null,
        'rapprochement_id' => null,
        'remise_id' => null,
        'saisi_par' => $this->user->id,
    ]);

    app(TransactionService::class)->delete($t1->fresh());

    expect(Transaction::find($t1->id))->toBeNull();
    expect(Transaction::withTrashed()->find($t1->id))->not->toBeNull();
});
