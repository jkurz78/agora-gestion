<?php

declare(strict_types=1);

use App\Enums\StatutOperation;
use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $assoc = Association::factory()->create();
    TenantContext::boot($assoc);
    $this->association = $assoc;

    $this->user = User::factory()->create();
    $this->user->associations()->attach($assoc->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $assoc->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $tenantId = (int) TenantContext::currentId();

    $this->compteType = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '706', 'intitule' => 'Prestations',
        'classe' => 7, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
    $this->compte606 = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/** Opération rattachée à un type, avec ou sans mouvement de charge daté. */
function opAvecTypeSelecteur(string $nom, bool $actifType, StatutOperation $statut, ?string $dateMouvement): Operation
{
    $tenantId = (int) TenantContext::currentId();

    $type = TypeOperation::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'Type '.$nom,
        'actif' => $actifType,
        'compte_id' => test()->compteType->id,
    ]);

    $op = Operation::factory()->create([
        'association_id' => $tenantId,
        'nom' => $nom,
        'statut' => $statut,
        'type_operation_id' => $type->id,
    ]);

    if ($dateMouvement !== null) {
        $tx = Transaction::factory()->asDepense()->create([
            'association_id' => $tenantId,
            'date' => $dateMouvement,
            'saisi_par' => auth()->id(),
        ]);
        $tx->lignes()->forceDelete();
        TransactionLigne::create([
            'transaction_id' => $tx->id,
            'montant' => 100.0,
            'compte_id' => test()->compte606->id,
            'operation_id' => $op->id,
            'debit' => 100.0,
            'credit' => 0.0,
        ]);
    }

    return $op;
}

/** @return list<string> noms des opérations présentes dans l'arbre */
function nomsArbreSelecteur(array $tree): array
{
    $noms = [];
    foreach ($tree as $compte) {
        foreach ($compte['types'] as $type) {
            foreach ($type['operations'] as $op) {
                $noms[] = $op['nom'];
            }
        }
    }
    sort($noms);

    return $noms;
}

it('ne propose que les opérations ayant un mouvement dans l\'exercice affiché', function () {
    opAvecTypeSelecteur('Avec mouvement', true, StatutOperation::EnCours, '2025-10-01');
    opAvecTypeSelecteur('Sans mouvement', true, StatutOperation::EnCours, null);
    opAvecTypeSelecteur('Mouvement ancien', true, StatutOperation::EnCours, '2024-10-01');

    $tree = Livewire::test(RapportCompteResultatOperations::class)->viewData('operationTree');

    expect(nomsArbreSelecteur($tree))->toBe(['Avec mouvement']);
});

it('propose une opération clôturée qui a un mouvement courant', function () {
    opAvecTypeSelecteur('Clôturée active', true, StatutOperation::Cloturee, '2025-10-01');

    $tree = Livewire::test(RapportCompteResultatOperations::class)->viewData('operationTree');

    expect(nomsArbreSelecteur($tree))->toBe(['Clôturée active']);
});

it('propose une opération dont le type est inactif', function () {
    opAvecTypeSelecteur('Type inactif', false, StatutOperation::EnCours, '2025-10-01');

    $tree = Livewire::test(RapportCompteResultatOperations::class)->viewData('operationTree');

    expect(nomsArbreSelecteur($tree))->toBe(['Type inactif']);
});

it('écarte une sélection URL devenue inéligible', function () {
    $op = opAvecTypeSelecteur('Sans mouvement', true, StatutOperation::EnCours, null);

    Livewire::test(RapportCompteResultatOperations::class, ['selectedOperationIds' => [$op->id]])
        ->assertSet('selectionIgnoree', true)
        ->assertViewHas('hasSelection', false);
});

it('conserve une sélection URL éligible', function () {
    $op = opAvecTypeSelecteur('Avec mouvement', true, StatutOperation::EnCours, '2025-10-01');

    Livewire::test(RapportCompteResultatOperations::class, ['selectedOperationIds' => [$op->id]])
        ->assertSet('selectionIgnoree', false)
        ->assertViewHas('hasSelection', true);
});

it('n\'affiche aucune opération éligible : le message ad hoc apparaît', function () {
    opAvecTypeSelecteur('Sans mouvement', true, StatutOperation::EnCours, null);

    Livewire::test(RapportCompteResultatOperations::class)
        ->assertViewHas('aucuneOperationEligible', true)
        ->assertSeeHtml('Aucune op');
});

it('affiche l\'alerte de sélection ignorée quand l\'URL pointe sur une opération inéligible', function () {
    $op = opAvecTypeSelecteur('Sans mouvement', true, StatutOperation::EnCours, null);

    Livewire::test(RapportCompteResultatOperations::class, ['selectedOperationIds' => [$op->id]])
        ->assertSeeHtml('n\'ont aucun mouvement');
});

it('charge l\'arbre du sélecteur sans N+1 malgré plusieurs types', function () {
    for ($i = 1; $i <= 5; $i++) {
        opAvecTypeSelecteur('Op '.$i, true, StatutOperation::EnCours, '2025-10-0'.$i);
    }

    DB::enableQueryLog();
    $tree = Livewire::test(RapportCompteResultatOperations::class)->viewData('operationTree');
    $countFew = count(DB::getQueryLog());
    DB::flushQueryLog();

    for ($i = 6; $i <= 15; $i++) {
        opAvecTypeSelecteur('Op '.$i, true, StatutOperation::EnCours, '2025-10-0'.($i % 9 + 1));
    }
    DB::flushQueryLog();

    Livewire::test(RapportCompteResultatOperations::class)->viewData('operationTree');
    $countMany = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(count($tree))->toBeGreaterThan(0);
    expect($countMany)->toBe($countFew);
});
