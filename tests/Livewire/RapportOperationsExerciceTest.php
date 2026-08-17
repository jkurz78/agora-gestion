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
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $this->compteVentilation = Compte::factory()->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/**
 * SEL-01 : depuis le lot précédent, ce n'est plus la date de l'opération qui
 * détermine son exercice mais la date des transactions qui portent ses
 * mouvements. Les deux tests ci-dessous, écrits pour l'ancien critère
 * (date_debut/date_fin), sont réécrits pour poser un vrai mouvement daté —
 * sinon ils ne testeraient plus rien (une opération sans mouvement est
 * toujours absente de l'arbre, quelles que soient ses dates).
 */
it('RapportCompteResultatOperations n\'affiche pas les opérations dont le mouvement est hors exercice', function () {
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteVentilation->id,
    ]);
    $op = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Op hors exercice',
        'statut' => StatutOperation::EnCours,
    ]);
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2024-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 50.0,
        'compte_id' => $this->compteVentilation->id,
        'operation_id' => $op->id,
        'debit' => 50.0,
        'credit' => 0.0,
    ]);

    $component = Livewire::test(RapportCompteResultatOperations::class);
    $tree = $component->viewData('operationTree');
    $allNames = collect($tree)->flatMap(fn ($sc) => collect($sc['types'])->flatMap(fn ($t) => collect($t['operations'])->pluck('nom')));
    expect($allNames)->not->toContain('Op hors exercice');
});

it('RapportCompteResultatOperations affiche les opérations clôturées ayant un mouvement dans l\'exercice', function () {
    $typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compteVentilation->id,
    ]);
    $op = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Op clôturée visible',
        'statut' => StatutOperation::Cloturee,
    ]);
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => $this->association->id,
        'date' => '2025-10-01',
        'saisi_par' => $this->user->id,
    ]);
    $tx->lignes()->forceDelete();
    TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => 50.0,
        'compte_id' => $this->compteVentilation->id,
        'operation_id' => $op->id,
        'debit' => 50.0,
        'credit' => 0.0,
    ]);

    $component = Livewire::test(RapportCompteResultatOperations::class);
    $tree = $component->viewData('operationTree');
    $allNames = collect($tree)->flatMap(fn ($sc) => collect($sc['types'])->flatMap(fn ($t) => collect($t['operations'])->pluck('nom')));
    expect($allNames)->toContain('Op clôturée visible');
});
