<?php

declare(strict_types=1);

use App\Livewire\RapportCompteResultatOperations;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * Exports (Excel + PDF) du rapport par opérations en portée « tous les
 * exercices ». Complète, côté génération de fichiers :
 *  - tests/Livewire/RapportOperationsPorteeTest.php (écran) ;
 *  - tests/Unit/Services/Rapports/CompteResultatBuilderPorteeTest.php (service).
 *
 * ⚠️ Horloge de test gelée au 2026-01-15 (tests/Pest.php) : l'exercice
 * affiché est 2025, du 2025-09-01 au 2026-08-31. Les fixtures sont datées
 * franchement à l'intérieur des fenêtres visées, jamais sur une borne.
 *
 * ⚠️ Un export dont aucune opération n'est éligible répond 422
 * (RapportExportController::operationsExport() — l'éligibilité vient de
 * OperationsEligiblesQuery, bornée sur l'exercice AFFICHÉ, indépendamment de
 * la portée d'affichage demandée). Les fixtures posent donc toujours un
 * mouvement réel sur 2025-10-10 (dans l'exercice affiché), jamais seulement
 * sur l'exercice antérieur.
 */

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);

    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $tenantId = (int) TenantContext::currentId();

    $this->compteCharge = Compte::create([
        'association_id' => $tenantId, 'numero_pcg' => '606', 'intitule' => 'Achats',
        'classe' => 6, 'lettrable' => false, 'actif' => true, 'est_systeme' => false, 'pour_inscriptions' => false,
    ]);

    $type = TypeOperation::factory()->create([
        'association_id' => $tenantId,
        'compte_id' => $this->compteCharge->id,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $tenantId,
        'nom' => 'Op export multi-exercices',
        'type_operation_id' => $type->id,
    ]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/** Dépense réelle imputée sur l'opération du test. */
function exportAllDepense(int $compteId, int $operationId, float $montant, string $date): TransactionLigne
{
    $tx = Transaction::factory()->asDepense()->create([
        'association_id' => (int) TenantContext::currentId(),
        'date' => $date,
        'saisi_par' => auth()->id(),
    ]);
    $tx->lignes()->forceDelete();

    return TransactionLigne::create([
        'transaction_id' => $tx->id,
        'montant' => $montant,
        'compte_id' => $compteId,
        'operation_id' => $operationId,
        'debit' => $montant,
        'credit' => 0.0,
    ]);
}

/**
 * Charge une réponse xlsx streamée en tableau de lignes, sans laisser de
 * fichier temporaire derrière soi.
 *
 * @return list<list<mixed>>
 */
function lireClasseur(TestResponse $response): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ropall').'.xlsx';
    file_put_contents($tmp, $response->streamedContent());
    $rows = IOFactory::load($tmp)->getActiveSheet()->toArray();
    @unlink($tmp);

    return $rows;
}

it('en portée all, le classeur porte une colonne Exercice, en 4e position', function () {
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'xlsx',
        'ops' => [$this->operation->id], 'exercices' => 'all',
    ]));
    $response->assertOk();

    $rows = lireClasseur($response);
    $headerRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Type');

    expect($headerRow)->not->toBeNull()
        ->and(array_slice($headerRow, 0, 4))->toBe(['Type', 'Famille', 'Compte', 'Exercice']);
});

it('en portée all, chaque exercice apparaît avec son propre montant', function () {
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'xlsx',
        'ops' => [$this->operation->id], 'exercices' => 'all',
    ]));
    $response->assertOk();

    $rows = collect(lireClasseur($response));

    // Colonne Exercice = index 3 (Type, Famille, Compte, Exercice, ..., Total) ;
    // avec parTiers=0 (défaut), le dernier élément de la ligne est le Total.
    $ligne2025 = $rows->first(fn ($r) => ($r[3] ?? null) === '2025-2026');
    $ligne2024 = $rows->first(fn ($r) => ($r[3] ?? null) === '2024-2025');

    expect($ligne2025)->not->toBeNull()
        ->and((float) end($ligne2025))->toBe(100.0)
        ->and($ligne2024)->not->toBeNull()
        ->and((float) end($ligne2024))->toBe(50.0);
});

it('en portée current, le classeur ne porte aucune colonne Exercice — non-régression', function () {
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'xlsx',
        'ops' => [$this->operation->id],
    ]));
    $response->assertOk();

    $rows = lireClasseur($response);
    $headerRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Type');

    expect($headerRow)->not->toBeNull()
        ->and($headerRow)->not->toContain('Exercice');
});

it('le sous-titre du PDF dit sans ambiguïté ce qu\'il couvre, en all comme en current', function () {
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');

    $subtitles = [];
    View::composer('pdf.rapport-operations', function ($view) use (&$subtitles): void {
        $subtitles[] = $view->getData()['subtitle'];
    });

    $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'pdf',
        'ops' => [$this->operation->id], 'exercices' => 'all',
    ]))->assertOk();

    $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'pdf',
        'ops' => [$this->operation->id],
    ]))->assertOk();

    expect($subtitles)->toHaveCount(2)
        ->and($subtitles[0])->toContain('Tous les exercices')
        ->and($subtitles[1])->toContain('Exercice affiché : 2025-2026');
});

it('l\'écran, le PDF et le classeur donnent le même total de charges en portée all', function () {
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 100.0, '2025-10-10');
    exportAllDepense((int) $this->compteCharge->id, (int) $this->operation->id, 50.0, '2024-10-10');

    $totalEcran = Livewire::test(RapportCompteResultatOperations::class)
        ->set('selectedOperationIds', [$this->operation->id])
        ->set('porteeExercices', 'all')
        ->viewData('totalCharges');

    expect($totalEcran)->toBe(150.0);

    $totalPdf = null;
    View::composer('pdf.rapport-operations', function ($view) use (&$totalPdf): void {
        $totalPdf = $view->getData()['totalCharges'];
    });

    $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'pdf',
        'ops' => [$this->operation->id], 'exercices' => 'all',
    ]))->assertOk();

    $response = $this->get(route('rapports.export', [
        'rapport' => 'operations', 'format' => 'xlsx',
        'ops' => [$this->operation->id], 'exercices' => 'all',
    ]));
    $response->assertOk();

    $rows = collect(lireClasseur($response));
    $ligneTotalDepenses = $rows->first(fn ($r) => ($r[2] ?? null) === 'TOTAL DÉPENSES');
    $totalXlsx = (float) end($ligneTotalDepenses);

    expect($totalPdf)->toBe($totalEcran)
        ->and($totalXlsx)->toBe($totalEcran);
});
