<?php

declare(strict_types=1);

use App\Livewire\Portail\MesActivites;
use App\Models\Association;
use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helper
// ─────────────────────────────────────────────────────────────────────────────
function makeFutureOperation(Association $asso, TypeOperation $typeOp): Operation
{
    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Activité future',
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $op->id,
        'date' => today()->addMonths(2),
    ]);

    return $op;
}

function makeCurrentOperation(Association $asso, TypeOperation $typeOp): Operation
{
    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Activité en cours',
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $op->id,
        'date' => today()->subMonth(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $op->id,
        'date' => today()->addMonth(),
    ]);

    return $op;
}

function makePastOperation(Association $asso, TypeOperation $typeOp): Operation
{
    $op = Operation::factory()->create([
        'association_id' => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom' => 'Activité terminée',
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id' => $op->id,
        'date' => today()->subMonths(2),
    ]);

    return $op;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cas 1 : Token actif sur À venir → bloc visible
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bloc magic-link sur une carte À venir avec token actif', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op = makeFutureOperation($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token' => '1234-5678',
        'expire_at' => today()->addDays(10),
        'rempli_at' => null,
    ]);

    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertSee('1234-5678')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('Ouvrir le questionnaire');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 2 : Token actif sur En cours → bloc visible
// ─────────────────────────────────────────────────────────────────────────────
it('affiche le bloc magic-link sur une carte En cours avec token actif', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op = makeCurrentOperation($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token' => 'ABCD-EFGH',
        'expire_at' => today()->addDays(10),
        'rempli_at' => null,
    ]);

    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertSee('ABCD-EFGH')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('Ouvrir le questionnaire');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 3 : Token actif mais participation Terminée → bloc absent
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas le bloc magic-link sur une carte Terminée même avec token actif', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op = makePastOperation($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token' => 'TERM-1234',
        'expire_at' => today()->addDays(10),
        'rempli_at' => null,
    ]);

    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertDontSee('TERM-1234');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 4 : Token expiré → bloc absent
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas le bloc magic-link si le token est expiré', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op = makeFutureOperation($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token' => 'EXPI-0000',
        'expire_at' => today()->subDay(),
        'rempli_at' => null,
    ]);

    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertDontSee('EXPI-0000');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 5 : Token déjà rempli → bloc absent
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas le bloc magic-link si le token est déjà rempli', function () {
    $asso = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op = makeFutureOperation($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id' => $tiers->id,
        'operation_id' => $op->id,
    ]);

    FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token' => 'REMS-9999',
        'expire_at' => today()->addDays(10),
        'rempli_at' => now(),
    ]);

    Livewire::test(MesActivites::class, ['association' => $asso, 'typeOperation' => $typeOp])
        ->assertDontSee('REMS-9999');
});
