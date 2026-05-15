<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\FormulaireToken;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    TenantContext::clear();
});

afterEach(function () {
    TenantContext::clear();
});

// ─────────────────────────────────────────────────────────────────────────────
// Helpers locaux (préfixés tdb_ pour éviter conflits avec MesActivitesMagicLinkTest)
// ─────────────────────────────────────────────────────────────────────────────
function tdb_currentOp(Association $asso, TypeOperation $typeOp, string $nom = 'Stage photo'): Operation
{
    $op = Operation::factory()->create([
        'association_id'    => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom'               => $nom,
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id'   => $op->id,
        'date'           => today()->subMonth(),
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id'   => $op->id,
        'date'           => today()->addMonth(),
    ]);

    return $op;
}

function tdb_pastOp(Association $asso, TypeOperation $typeOp): Operation
{
    $op = Operation::factory()->create([
        'association_id'    => $asso->id,
        'type_operation_id' => $typeOp->id,
        'nom'               => 'Activité passée',
    ]);
    Seance::factory()->create([
        'association_id' => $asso->id,
        'operation_id'   => $op->id,
        'date'           => today()->subMonths(2),
    ]);

    return $op;
}

function tdb_makeToken(Association $asso, Participant $participant, string $token): FormulaireToken
{
    return FormulaireToken::create([
        'association_id' => $asso->id,
        'participant_id' => $participant->id,
        'token'          => $token,
        'expire_at'      => today()->addDays(10),
        'rempli_at'      => null,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Cas 1 : 1 token actif sur participation En cours → alerte visible
// ─────────────────────────────────────────────────────────────────────────────
it('affiche une alerte Action requise pour un token actif sur opération en cours', function () {
    $asso  = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create([
        'association_id' => $asso->id,
        'nom'            => 'Formation',
    ]);
    $op = tdb_currentOp($asso, $typeOp, 'Stage photo');

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id'       => $tiers->id,
        'operation_id'   => $op->id,
    ]);
    tdb_makeToken($asso, $participant, 'TDB1-AAAA');

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertSeeText('Action requise')
        ->assertSeeText('Formation')
        ->assertSeeText('Stage photo')
        ->assertSee('target="_blank"', false)
        ->assertSee(route('formulaire.index', ['token' => 'TDB1-AAAA']));
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 2 : Token sur opération Terminée → AUCUNE alerte
// ─────────────────────────────────────────────────────────────────────────────
it('ne montre pas d\'alerte pour un token sur opération terminée', function () {
    $asso  = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id]);
    $op     = tdb_pastOp($asso, $typeOp);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $participant = Participant::factory()->create([
        'association_id' => $asso->id,
        'tiers_id'       => $tiers->id,
        'operation_id'   => $op->id,
    ]);
    tdb_makeToken($asso, $participant, 'TDB2-PAST');

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertDontSeeText('Action requise');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 3 : 5 tokens actifs → 3 alertes + mention "+ 2 autre(s)"
// ─────────────────────────────────────────────────────────────────────────────
it('limite les alertes à 3 et affiche le compteur du surplus', function () {
    $asso  = Association::factory()->create();
    TenantContext::boot($asso);

    $typeOp = TypeOperation::factory()->create(['association_id' => $asso->id, 'nom' => 'Atelier']);
    $tiers  = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    for ($i = 1; $i <= 5; $i++) {
        $op = tdb_currentOp($asso, $typeOp, "Activité {$i}");
        $p  = Participant::factory()->create([
            'association_id' => $asso->id,
            'tiers_id'       => $tiers->id,
            'operation_id'   => $op->id,
        ]);
        tdb_makeToken($asso, $p, "TDB3-{$i}000");
    }

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertSeeText('Action requise')
        ->assertSeeText('+ 2 autre(s) action(s) en attente');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cas 4 : Aucun token actif → pas d'alerte
// ─────────────────────────────────────────────────────────────────────────────
it('n\'affiche pas d\'alerte quand il n\'y a aucun token actif', function () {
    $asso  = Association::factory()->create();
    TenantContext::boot($asso);

    $tiers = Tiers::factory()->create(['association_id' => $asso->id]);
    Auth::guard('tiers-portail')->login($tiers);

    $this->get("/{$asso->slug}/portail/")
        ->assertStatus(200)
        ->assertDontSeeText('Action requise');
});
