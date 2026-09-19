<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;

/*
 * La colonne de participation optionnelle suit TypeOperation::
 * libelleParticipationSeance() sur la feuille d'émargement, la matrice PDF et
 * l'export Excel. Les PDF sont interceptés : on rend la vue avec les données
 * que le contrôleur lui passe, et on inspecte le HTML.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

/** @return array{operation: Operation, seance: Seance} */
function operationParticipationPdfTest(object $ctx, ?string $libelle, bool $parcours = false): array
{
    $type = TypeOperation::factory()->create([
        'association_id' => $ctx->association->id,
        'formulaire_parcours_therapeutique' => $parcours,
        'participation_seance_active' => $libelle !== null,
        'participation_seance_libelle' => $libelle,
    ]);
    $operation = Operation::factory()->create([
        'association_id' => $ctx->association->id,
        'type_operation_id' => $type->id,
    ]);
    $seance = Seance::create(['operation_id' => $operation->id, 'numero' => 1, 'date' => '2025-11-15']);
    Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $ctx->association->id])->id,
        'operation_id' => $operation->id,
        'date_inscription' => now(),
    ]);

    return ['operation' => $operation, 'seance' => $seance];
}

/** Rend en HTML la vue PDF que la route demande à DomPDF. */
function htmlPdfParticipationTest(object $ctx, string $url): string
{
    $html = '';
    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data) use (&$html): bool {
            $html = view($view, $data)->render();

            return true;
        })
        ->andReturnSelf();
    Pdf::shouldReceive('setPaper')->andReturnSelf();
    Pdf::shouldReceive('stream')->andReturn(response('', 200));

    $ctx->get($url)->assertOk();

    return $html;
}

it('la feuille d\'émargement affiche la colonne avec le libellé paramétré', function () {
    ['operation' => $operation, 'seance' => $seance] = operationParticipationPdfTest($this, 'Repas');

    $html = htmlPdfParticipationTest($this, route('operations.seances.emargement-pdf', [$operation, $seance]));

    expect($html)->toContain('<th class="col-kine">Repas</th>');
});

it('la feuille d\'émargement n\'a pas la colonne sans l\'option, même en parcours, et garde la liste fermée', function () {
    ['operation' => $operation, 'seance' => $seance] = operationParticipationPdfTest($this, null, parcours: true);

    $html = htmlPdfParticipationTest($this, route('operations.seances.emargement-pdf', [$operation, $seance]));

    expect($html)->not->toContain('class="col-kine"')
        ->and(substr_count($html, 'class="col-signature"'))->toBe(2); // en-tête + 1 participant, aucune ligne vide
});

it('la feuille d\'émargement hors parcours ajoute des lignes vides, même avec l\'option', function () {
    ['operation' => $operation, 'seance' => $seance] = operationParticipationPdfTest($this, 'Kiné');

    $html = htmlPdfParticipationTest($this, route('operations.seances.emargement-pdf', [$operation, $seance]));

    expect(substr_count($html, 'class="col-signature"'))->toBeGreaterThan(2)
        ->and($html)->toContain('<th class="col-kine">Kiné</th>');
});

it('la matrice PDF affiche la colonne avec l\'initiale du libellé', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, 'Repas');

    $html = htmlPdfParticipationTest($this, route('operations.seances.matrice-pdf', $operation));

    expect($html)->toContain('class="col-participation"')
        ->and($html)->toMatch('/class="col-participation-entete"[^>]*>R</');
});

it('la matrice PDF n\'a pas la colonne sans l\'option, même en parcours', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, null, parcours: true);

    $html = htmlPdfParticipationTest($this, route('operations.seances.matrice-pdf', $operation));

    expect($html)->not->toContain('class="col-participation');
});
