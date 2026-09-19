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
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

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

it('la feuille d\'émargement garde les lignes vides sans l\'option, hors parcours, mais sans colonne', function () {
    ['operation' => $operation, 'seance' => $seance] = operationParticipationPdfTest($this, null);

    $html = htmlPdfParticipationTest($this, route('operations.seances.emargement-pdf', [$operation, $seance]));

    expect($html)->not->toContain('class="col-kine"')
        ->and(substr_count($html, 'class="col-signature"'))->toBeGreaterThan(2);
});

it('la feuille d\'émargement hors parcours ajoute des lignes vides, même avec l\'option', function () {
    ['operation' => $operation, 'seance' => $seance] = operationParticipationPdfTest($this, 'Kiné');

    $html = htmlPdfParticipationTest($this, route('operations.seances.emargement-pdf', [$operation, $seance]));

    $signatureCount = substr_count($html, 'class="col-signature"');

    // class="col-signature" : 1 en-tête + 1 par ligne (participant ou vide).
    // class="col-kine" en <td> : 1 par ligne seulement (l'en-tête est un <th>).
    expect($signatureCount)->toBeGreaterThan(2)
        ->and($html)->toContain('<th class="col-kine">Kiné</th>')
        ->and(substr_count($html, '<td class="col-kine">'))->toBe($signatureCount - 1);
});

it('la matrice PDF affiche la colonne avec l\'initiale du libellé', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, 'Repas');

    $html = htmlPdfParticipationTest($this, route('operations.seances.matrice-pdf', $operation));

    expect($html)->toContain('class="col-participation"')
        ->and($html)->toMatch('/class="col-participation-entete"[^>]*>R</')
        ->and($html)->toContain('colspan="2"')
        ->and($html)->toContain('rowspan="4"');
});

it('la matrice PDF n\'a pas la colonne sans l\'option, même en parcours', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, null, parcours: true);

    $html = htmlPdfParticipationTest($this, route('operations.seances.matrice-pdf', $operation));

    expect($html)->not->toContain('class="col-participation')
        ->and($html)->toContain('colspan="1"')
        ->and($html)->toContain('rowspan="3"');
});

/**
 * Charge le classeur exporté et renvoie la feuille active (lignes ET fusions).
 * Nettoie le fichier temporaire : deleteFileAfterSend() ne s'exécute jamais en
 * test (pas d'appel réel à send()), sans quoi storage/app/temp accumule des .xlsx.
 */
function feuilleExportParticipationTest(object $ctx, Operation $operation): Worksheet
{
    $response = $ctx->get(route('operations.seances.export', $operation));
    $response->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $sheet = IOFactory::load($path)->getActiveSheet();
    @unlink($path);

    return $sheet;
}

it('l\'export Excel nomme la colonne de participation avec son libellé', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, 'Repas');
    Seance::create(['operation_id' => $operation->id, 'numero' => 2]);

    $sheet = feuilleExportParticipationTest($this, $operation);
    $lignes = $sheet->toArray(null, true, false);
    $merges = $sheet->getMergeCells();

    // Ligne 1 : numéros de séance, chacun fusionné sur 2 colonnes (Présence + participation).
    expect($lignes[0])->toBe(['Participant', 'S1', null, 'S2', null]);
    expect($merges)->toHaveKey('B1:C1');
    expect($merges)->toHaveKey('D1:E1');

    // Ligne 4 : sous-en-têtes « Présence » / libellé pour chaque séance.
    expect(array_values(array_filter($lignes[3], fn ($v) => $v !== null && $v !== '')))
        ->toBe(['Présence', 'Repas', 'Présence', 'Repas']);
});

it('l\'export Excel n\'a qu\'une colonne par séance sans l\'option', function () {
    ['operation' => $operation] = operationParticipationPdfTest($this, null, parcours: true);
    Seance::create(['operation_id' => $operation->id, 'numero' => 2]);

    $sheet = feuilleExportParticipationTest($this, $operation);
    $lignes = $sheet->toArray(null, true, false);

    expect($lignes[0])->toBe(['Participant', 'S1', 'S2']);
    expect(count($lignes[0]))->toBe(3); // Participant + 1 colonne par séance

    // Aucune fusion horizontale (2 colonnes) : seules les fusions verticales du nom
    // de participant (même colonne des deux côtés) sont attendues, ex. A5:A6.
    foreach (array_keys($sheet->getMergeCells()) as $range) {
        [$debut, $fin] = explode(':', $range);
        expect(preg_replace('/\d+/', '', $debut))->toBe(preg_replace('/\d+/', '', $fin));
    }

    expect(array_values(array_filter($lignes[3], fn ($v) => $v !== null && $v !== '')))
        ->toBe(['Présence', 'Présence']);
});
