<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesLogos;
use App\Livewire\AnalysePivot;
use App\Models\Association;
use App\Services\ExerciceService;
use App\Services\ProvisionService;
use App\Services\RapportService;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RapportExportController extends Controller
{
    use ResolvesLogos;

    /** Rapports and their allowed formats */
    private const RAPPORTS = [
        'compte-resultat' => ['xlsx', 'pdf'],
        'operations' => ['xlsx', 'pdf'],
        'flux-tresorerie' => ['xlsx', 'pdf'],
        'analyse-financier' => ['xlsx'],
        'analyse-participants' => ['xlsx'],
    ];

    /** PDF orientations */
    private const PDF_ORIENTATION = [
        'compte-resultat' => 'portrait',
        'operations' => 'landscape',
        'flux-tresorerie' => 'portrait',
    ];

    /** Human-readable rapport names (for filenames and titles) */
    private const TITLES = [
        'compte-resultat' => 'Compte de resultat',
        'operations' => 'CR par operations',
        'flux-tresorerie' => 'Flux de tresorerie',
        'analyse-financier' => 'Analyse financiere',
        'analyse-participants' => 'Analyse participants',
    ];

    public function __invoke(
        Request $request,
        string $rapport,
        string $format,
        RapportService $rapportService,
        ExerciceService $exerciceService,
    ): Response {
        if (! isset(self::RAPPORTS[$rapport]) || ! in_array($format, self::RAPPORTS[$rapport], true)) {
            throw new NotFoundHttpException;
        }

        $exercice = $request->integer('exercice', $exerciceService->current());
        $label = $exerciceService->label($exercice);

        $association = CurrentAssociation::get();
        $filename = $this->buildFilename($association, $rapport, $label, $format);

        return match ($format) {
            'xlsx' => $this->exportXlsx($rapport, $exercice, $label, $request, $rapportService, $exerciceService, $filename),
            'pdf' => $this->exportPdf($rapport, $exercice, $label, $request, $rapportService, $association, $filename),
        };
    }

    private function buildFilename(?Association $association, string $rapport, string $label, string $format): string
    {
        $prefix = $association?->nom
            ? Str::ascii($association->nom).' - '
            : '';

        return $prefix.self::TITLES[$rapport].' '.$label.'.'.$format;
    }

    // ── Excel exports ─────────────────────────────────────────────────────────

    private function exportXlsx(
        string $rapport,
        int $exercice,
        string $label,
        Request $request,
        RapportService $rapportService,
        ExerciceService $exerciceService,
        string $filename,
    ): StreamedResponse {
        $spreadsheet = match ($rapport) {
            'compte-resultat' => $this->xlsxCompteResultat($rapportService, $exercice, $label),
            'operations' => $this->xlsxOperations($rapportService, $exercice, $request),
            'flux-tresorerie' => $this->xlsxFluxTresorerie($rapportService, $exercice),
            'analyse-financier' => $this->xlsxAnalyse('financier', $exercice, $exerciceService),
            'analyse-participants' => $this->xlsxAnalyse('participants', $exercice, $exerciceService),
        };

        $this->autoSizeColumns($spreadsheet);

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }

    private function xlsxCompteResultat(RapportService $rapportService, int $exercice, string $label): Spreadsheet
    {
        $data = $rapportService->compteDeResultat($exercice);

        $provisionService = app(ProvisionService::class);
        $provisions = $provisionService->provisionsExercice($exercice);
        $provisionsN1 = $provisionService->provisionsExercice($exercice - 1);
        $extournes = $provisionService->extournesExercice($exercice);
        $extournesN1 = $provisionService->extournesExercice($exercice - 1);
        $totalProvisions = $provisionService->totalProvisions($exercice);
        $totalProvisionsN1 = $provisionService->totalProvisions($exercice - 1);
        $totalExtournes = $provisionService->totalExtournes($exercice);
        $totalExtournesN1 = $provisionService->totalExtournes($exercice - 1);

        $labelN1 = ($exercice - 1).'-'.$exercice;
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compte de résultat');

        $row = 1;
        $sheet->fromArray([['Type', 'Catégorie', 'Sous-catégorie', $labelN1, $label, 'Budget', 'Écart']], null, 'A'.$row);
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges']], ['Produit', $data['produits']]] as [$type, $sections]) {
            foreach ($sections as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $ecart = ($sc['budget'] !== null && $sc['montant_n'] !== null)
                        ? (float) $sc['montant_n'] - (float) $sc['budget']
                        : null;
                    $sheet->fromArray([[
                        $type,
                        $cat['label'],
                        $sc['label'],
                        $sc['montant_n1'] !== null ? (float) $sc['montant_n1'] : null,
                        (float) $sc['montant_n'],
                        $sc['budget'] !== null ? (float) $sc['budget'] : null,
                        $ecart,
                    ]], null, 'A'.$row);
                    $row++;
                }
                // Category subtotal
                $sheet->fromArray([[
                    $type,
                    $cat['label'],
                    'TOTAL',
                    $cat['montant_n1'] !== null ? (float) $cat['montant_n1'] : null,
                    (float) $cat['montant_n'],
                    $cat['budget'] !== null ? (float) $cat['budget'] : null,
                    ($cat['budget'] !== null) ? (float) $cat['montant_n'] - (float) $cat['budget'] : null,
                ]], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
                $row++;
            }
        }

        // Compute résultat values
        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = (float) $totalProduitsN - (float) $totalChargesN;
        $resultatCourantN1 = (float) $totalProduitsN1 - (float) $totalChargesN1;
        $resultatBrut = $resultatCourant + $totalExtournes;
        $resultatBrutN1 = $resultatCourantN1 + $totalExtournesN1;
        $resultatNet = $resultatBrut + $totalProvisions;
        $resultatNetN1 = $resultatBrutN1 + $totalProvisionsN1;

        // Blank separator row
        $row++;

        // Extournes section
        if ($extournes->isNotEmpty() || $extournesN1->isNotEmpty()) {
            $extournesN1Keyed = $extournesN1->keyBy(fn (array $e) => $e['libelle'].'|'.$e['sous_categorie_id']);
            $extournesNKeyed = $extournes->keyBy(fn (array $e) => $e['libelle'].'|'.$e['sous_categorie_id']);
            $allExtourneKeys = $extournesN1Keyed->keys()->merge($extournesNKeyed->keys())->unique();

            foreach ($allExtourneKeys as $key) {
                $eN = $extournesNKeyed->get($key);
                $eN1 = $extournesN1Keyed->get($key);
                $scNom = $eN['sous_categorie_nom'] ?? $eN1['sous_categorie_nom'];
                $libelle = $eN['libelle'] ?? $eN1['libelle'];
                $sheet->fromArray([[
                    'Extourne',
                    $scNom,
                    $libelle,
                    $eN1 !== null ? $eN1['montant_signe'] : null,
                    $eN !== null ? $eN['montant_signe'] : null,
                    null,
                    null,
                ]], null, 'A'.$row);
                $row++;
            }

            // Extournes total row
            $sheet->fromArray([[
                'Extourne',
                '',
                'TOTAL EXTOURNES',
                $totalExtournesN1 !== 0.0 ? $totalExtournesN1 : null,
                $totalExtournes !== 0.0 ? $totalExtournes : null,
                null,
                null,
            ]], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
            $row++;
        }

        // Résultat brut row
        $sheet->fromArray([[
            '',
            '',
            'RÉSULTAT BRUT',
            $resultatBrutN1,
            $resultatBrut,
            null,
            null,
        ]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
        $row++;

        // Provisions section
        if ($provisions->isNotEmpty() || $provisionsN1->isNotEmpty()) {
            $provisionsN1Keyed = $provisionsN1->keyBy(fn (array $p) => $p['libelle'].'|'.$p['sous_categorie_id']);
            $provisionsNKeyed = $provisions->keyBy(fn (array $p) => $p['libelle'].'|'.$p['sous_categorie_id']);
            $allProvisionKeys = $provisionsN1Keyed->keys()->merge($provisionsNKeyed->keys())->unique();

            foreach ($allProvisionKeys as $key) {
                $pN = $provisionsNKeyed->get($key);
                $pN1 = $provisionsN1Keyed->get($key);
                $scNom = $pN['sous_categorie_nom'] ?? $pN1['sous_categorie_nom'];
                $libelle = $pN['libelle'] ?? $pN1['libelle'];
                $sheet->fromArray([[
                    'Provision',
                    $scNom,
                    $libelle,
                    $pN1 !== null ? $pN1['montant_signe'] : null,
                    $pN !== null ? $pN['montant_signe'] : null,
                    null,
                    null,
                ]], null, 'A'.$row);
                $row++;
            }

            // Provisions total row
            $sheet->fromArray([[
                'Provision',
                '',
                'TOTAL PROVISIONS',
                $totalProvisionsN1 !== 0.0 ? $totalProvisionsN1 : null,
                $totalProvisions !== 0.0 ? $totalProvisions : null,
                null,
                null,
            ]], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
            $row++;
        }

        // Résultat net ajusté row
        $sheet->fromArray([[
            '',
            '',
            'RÉSULTAT AJUSTÉ',
            $resultatNetN1,
            $resultatNet,
            null,
            null,
        ]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':G'.$row)->getFont()->setBold(true);
        $row++;

        // Format number columns (covers all rows including provisions/extournes)
        $sheet->getStyle('D2:G'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        return $spreadsheet;
    }

    private function xlsxOperations(RapportService $rapportService, int $exercice, Request $request): Spreadsheet
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');
        $mode = $request->query('mode', 'realise');
        $previsionnel = $mode !== 'realise';
        $parOperations = $request->boolean('parops');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers, $previsionnel, $parOperations);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $modeLabel = match ($mode) {
            'comparaison' => ' (Comparaison)',
            'projection' => ' (Projection)',
            default => '',
        };
        $sheet->setTitle('CR par opérations'.$modeLabel);

        $seances = $data['seances'] ?? [];
        $operationNames = $data['operation_names'] ?? [];
        $row = 1;

        // Build flat previsions lookup: sc_id => { montant, seances: {num => float}, operations?: {op_id => float} }
        // Only populated when $previsionnel is true.
        $buildPrevIdx = function (array $hierarchy) use ($parOperations): array {
            $idx = [];
            foreach ($hierarchy as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    // previsions nodes use 'sous_categorie_id' as the key field name
                    $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                    $entry = [
                        'montant' => (float) ($sc['montant'] ?? $sc['total'] ?? 0),
                        'seances' => $sc['seances'] ?? [],
                    ];
                    if ($parOperations) {
                        $entry['operations'] = $sc['operations'] ?? [];
                    }
                    $idx[$scId] = $entry;
                }
            }

            return $idx;
        };

        $prevChargesIdx = $previsionnel ? $buildPrevIdx($data['previsions_charges'] ?? []) : [];
        $prevProduitsIdx = $previsionnel ? $buildPrevIdx($data['previsions_produits'] ?? []) : [];

        // Projection helper: use réel if > 0, else use prévu
        $projeter = fn (float $realise, float $prevu): float => $realise > 0 ? $realise : $prevu;

        // ── parOperations: header and data rows ──────────────────────────────────
        if ($parOperations) {
            if ($mode === 'comparaison') {
                // Two header rows: row 1 has op names (merged 3 cols each) + Total; row 2 has Prévu/Réel/Écart per op
                $headerRow1 = ['Type', 'Catégorie', 'Sous-catégorie'];
                $headerRow2 = ['', '', ''];
                $colIndex = 4; // 1-based column index, A=1
                foreach ($operationNames as $opId => $opName) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIndex);
                    $colLetterEnd = Coordinate::stringFromColumnIndex($colIndex + 2);
                    $headerRow1[] = $opName;
                    $headerRow1[] = '';
                    $headerRow1[] = '';
                    $headerRow2[] = 'Prévu';
                    $headerRow2[] = 'Réel';
                    $headerRow2[] = 'Écart';
                    $sheet->mergeCells($colLetter.'1:'.$colLetterEnd.'1');
                    $colIndex += 3;
                }
                // Total (3 sub-columns)
                $totalColLetter = Coordinate::stringFromColumnIndex($colIndex);
                $totalColLetterEnd = Coordinate::stringFromColumnIndex($colIndex + 2);
                $headerRow1[] = 'Total';
                $headerRow1[] = '';
                $headerRow1[] = '';
                $headerRow2[] = 'Prévu';
                $headerRow2[] = 'Réel';
                $headerRow2[] = 'Écart';
                $sheet->mergeCells($totalColLetter.'1:'.$totalColLetterEnd.'1');

                $sheet->fromArray([$headerRow1], null, 'A1');
                $sheet->fromArray([$headerRow2], null, 'A2');
                $sheet->getStyle('A1:'.chr(64 + count($headerRow1)).'1')->getFont()->setBold(true);
                $sheet->getStyle('A2:'.chr(64 + count($headerRow2)).'2')->getFont()->setBold(true);
                $headers = $headerRow2; // for column count reference
                $row = 3;
            } else {
                // Single header row: op names + Total
                $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
                foreach ($operationNames as $opName) {
                    $headers[] = $opName;
                }
                $headers[] = 'Total';
                $sheet->fromArray([$headers], null, 'A1');
                $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);
                $row = 2;
            }

            foreach ([['Charge', $data['charges'], $prevChargesIdx], ['Produit', $data['produits'], $prevProduitsIdx]] as [$type, $sections, $prevIdx]) {
                foreach ($sections as $cat) {
                    foreach ($cat['sous_categories'] as $sc) {
                        $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                        $prevSc = $prevIdx[$scId] ?? ['montant' => 0.0, 'operations' => []];

                        $values = [$type, $cat['label'], $sc['label']];

                        if ($mode === 'comparaison') {
                            $totalPrevu = 0.0;
                            $totalRealise = 0.0;
                            foreach ($operationNames as $opId => $opName) {
                                $realise = (float) ($sc['operations'][$opId] ?? 0);
                                $prevu = (float) ($prevSc['operations'][$opId] ?? 0);
                                $values[] = $prevu;
                                $values[] = $realise;
                                $values[] = $realise - $prevu;
                                $totalPrevu += $prevu;
                                $totalRealise += $realise;
                            }
                            $values[] = $totalPrevu;
                            $values[] = $totalRealise;
                            $values[] = $totalRealise - $totalPrevu;
                        } elseif ($mode === 'projection') {
                            $projectedTotal = 0.0;
                            foreach ($operationNames as $opId => $opName) {
                                $realise = (float) ($sc['operations'][$opId] ?? 0);
                                $prevu = (float) ($prevSc['operations'][$opId] ?? 0);
                                $projected = $projeter($realise, $prevu);
                                $values[] = $projected;
                                $projectedTotal += $projected;
                            }
                            $values[] = $projectedTotal;
                        } else {
                            // realise
                            $total = 0.0;
                            foreach ($operationNames as $opId => $opName) {
                                $val = (float) ($sc['operations'][$opId] ?? 0);
                                $values[] = $val;
                                $total += $val;
                            }
                            $values[] = $total;
                        }

                        $sheet->fromArray([$values], null, 'A'.$row);
                        $row++;
                    }

                    // Category total row
                    $catValues = [$type, $cat['label'], 'TOTAL'];

                    if ($mode === 'comparaison') {
                        $catTotalPrevu = 0.0;
                        $catTotalRealise = 0.0;
                        foreach ($operationNames as $opId => $opName) {
                            $catRealise = (float) ($cat['operations'][$opId] ?? 0);
                            $catPrevu = 0.0;
                            foreach ($cat['sous_categories'] as $sc) {
                                $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                                $catPrevu += (float) ($prevIdx[$scId]['operations'][$opId] ?? 0);
                            }
                            $catValues[] = $catPrevu;
                            $catValues[] = $catRealise;
                            $catValues[] = $catRealise - $catPrevu;
                            $catTotalPrevu += $catPrevu;
                            $catTotalRealise += $catRealise;
                        }
                        $catValues[] = $catTotalPrevu;
                        $catValues[] = $catTotalRealise;
                        $catValues[] = $catTotalRealise - $catTotalPrevu;
                    } elseif ($mode === 'projection') {
                        $catProjectedTotal = 0.0;
                        foreach ($operationNames as $opId => $opName) {
                            $catProjected = 0.0;
                            foreach ($cat['sous_categories'] as $sc) {
                                $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                                $realise = (float) ($sc['operations'][$opId] ?? 0);
                                $prevu = (float) ($prevIdx[$scId]['operations'][$opId] ?? 0);
                                $catProjected += $projeter($realise, $prevu);
                            }
                            $catValues[] = $catProjected;
                            $catProjectedTotal += $catProjected;
                        }
                        $catValues[] = $catProjectedTotal;
                    } else {
                        $catTotal = 0.0;
                        foreach ($operationNames as $opId => $opName) {
                            $val = (float) ($cat['operations'][$opId] ?? 0);
                            $catValues[] = $val;
                            $catTotal += $val;
                        }
                        $catValues[] = $catTotal;
                    }

                    $sheet->fromArray([$catValues], null, 'A'.$row);
                    $sheet->getStyle('A'.$row.':'.chr(64 + count($catValues)).$row)->getFont()->setBold(true);
                    $row++;
                }
            }

            // Format number columns (D onwards)
            $lastCol = chr(64 + count($mode === 'comparaison' ? $headers : (array_merge(['', '', ''], array_values($operationNames), ['']))));
            if ($row > ($mode === 'comparaison' ? 4 : 3)) {
                $startRow = $mode === 'comparaison' ? 3 : 2;
                $sheet->getStyle('D'.$startRow.':'.$lastCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return $spreadsheet;
        }

        // ── Standard (non-parOperations) header ──────────────────────────────────
        if ($parSeances) {
            $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            foreach ($seances as $s) {
                $headers[] = $s === 0 ? 'Hors séances' : 'S'.$s;
            }
            $headers[] = 'Total';
        } else {
            $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            if ($mode === 'projection') {
                $headers[] = 'Projeté';
            } else {
                $headers[] = 'Montant';
            }
        }
        if ($mode === 'comparaison') {
            $headers[] = 'Prévu';
            $headers[] = 'Écart';
        }
        $sheet->fromArray([$headers], null, 'A'.$row);
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges'], $prevChargesIdx], ['Produit', $data['produits'], $prevProduitsIdx]] as [$type, $sections, $prevIdx]) {
            foreach ($sections as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                    $prevSc = $prevIdx[$scId] ?? ['montant' => 0.0, 'seances' => []];

                    if ($parTiers && ! empty($sc['tiers'])) {
                        foreach ($sc['tiers'] as $t) {
                            $values = [$type, $cat['label'], $sc['label'], $t['label']];
                            if ($parSeances) {
                                foreach ($seances as $s) {
                                    $values[] = (float) ($t['seances'][$s] ?? 0);
                                }
                                $values[] = (float) ($t['total'] ?? 0);
                            } else {
                                $values[] = (float) ($t['montant'] ?? 0);
                            }
                            // Previsionnel: les tiers individuels ne sont pas restitués ici par choix de design ;
                            // la granularité affichée s'arrête à la sous-catégorie. Les prévisions par tiers
                            // existent en DB (encadrement_previsions.tiers_id) mais sont agrégées au niveau sc.
                            if ($mode === 'comparaison') {
                                $values[] = '';
                                $values[] = '';
                            }
                            $sheet->fromArray([$values], null, 'A'.$row);
                            $row++;
                        }
                    }
                    // Sous-catégorie subtotal row
                    $values = [$type, $cat['label'], $sc['label']];
                    if ($parTiers) {
                        $values[] = 'TOTAL';
                    }
                    if ($parSeances) {
                        if ($mode === 'projection') {
                            foreach ($seances as $s) {
                                $realise = (float) ($sc['seances'][$s] ?? 0);
                                $prevu = (float) ($prevSc['seances'][$s] ?? 0);
                                $values[] = $projeter($realise, $prevu);
                            }
                            $projectedTotal = 0.0;
                            foreach ($seances as $s) {
                                $projectedTotal += $projeter((float) ($sc['seances'][$s] ?? 0), (float) ($prevSc['seances'][$s] ?? 0));
                            }
                            $values[] = $projectedTotal;
                            $realise = $projectedTotal; // for cat total accumulation reference (not used below)
                        } else {
                            foreach ($seances as $s) {
                                $values[] = (float) ($sc['seances'][$s] ?? 0);
                            }
                            $realise = (float) ($sc['total'] ?? 0);
                            $values[] = $realise;
                        }
                    } else {
                        if ($mode === 'projection') {
                            $realise = (float) ($sc['total'] ?? $sc['montant'] ?? 0);
                            $prevu = (float) $prevSc['montant'];
                            $values[] = $projeter($realise, $prevu);
                            $realise = $projeter($realise, $prevu);
                        } else {
                            $realise = (float) ($sc['montant'] ?? 0);
                            $values[] = $realise;
                        }
                    }
                    if ($mode === 'comparaison') {
                        $prevu = (float) $prevSc['montant'];
                        $values[] = $prevu;
                        $values[] = $realise - $prevu;
                    }
                    $sheet->fromArray([$values], null, 'A'.$row);
                    if ($parTiers) {
                        $sheet->getStyle('A'.$row.':'.chr(64 + count($headers)).$row)->getFont()->setBold(true);
                    }
                    $row++;
                }
                // Category total row
                $values = [$type, $cat['label'], 'TOTAL'];
                if ($parTiers) {
                    $values[] = '';
                }
                if ($parSeances) {
                    if ($mode === 'projection') {
                        foreach ($seances as $s) {
                            $catSeanceProjected = 0.0;
                            foreach ($cat['sous_categories'] as $sc) {
                                $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                                $prevSc = $prevIdx[$scId] ?? ['montant' => 0.0, 'seances' => []];
                                $catSeanceProjected += $projeter((float) ($sc['seances'][$s] ?? 0), (float) ($prevSc['seances'][$s] ?? 0));
                            }
                            $values[] = $catSeanceProjected;
                        }
                        $catProjected = 0.0;
                        foreach ($seances as $s) {
                            foreach ($cat['sous_categories'] as $sc) {
                                $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                                $prevSc = $prevIdx[$scId] ?? ['montant' => 0.0, 'seances' => []];
                                $catProjected += $projeter((float) ($sc['seances'][$s] ?? 0), (float) ($prevSc['seances'][$s] ?? 0));
                            }
                        }
                        $catRealise = $catProjected;
                        $values[] = $catRealise;
                    } else {
                        foreach ($seances as $s) {
                            $values[] = (float) ($cat['seances'][$s] ?? 0);
                        }
                        $catRealise = (float) ($cat['total'] ?? 0);
                        $values[] = $catRealise;
                    }
                } else {
                    if ($mode === 'projection') {
                        $catProjected = 0.0;
                        foreach ($cat['sous_categories'] as $sc) {
                            $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                            $prevSc = $prevIdx[$scId] ?? ['montant' => 0.0, 'seances' => []];
                            $scRealise = (float) ($sc['total'] ?? $sc['montant'] ?? 0);
                            $scPrevu = (float) $prevSc['montant'];
                            $catProjected += $projeter($scRealise, $scPrevu);
                        }
                        $catRealise = $catProjected;
                        $values[] = $catRealise;
                    } else {
                        $catRealise = (float) ($cat['montant'] ?? 0);
                        $values[] = $catRealise;
                    }
                }
                if ($mode === 'comparaison') {
                    // Compute cat prevu by summing sc previsions for sous_categories of this cat
                    $catPrevu = 0.0;
                    foreach ($cat['sous_categories'] as $sc) {
                        $scId = (int) ($sc['sous_categorie_id'] ?? $sc['id'] ?? 0);
                        $catPrevu += (float) ($prevIdx[$scId]['montant'] ?? 0);
                    }
                    $values[] = $catPrevu;
                    $values[] = $catRealise - $catPrevu;
                }
                $sheet->fromArray([$values], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':'.chr(64 + count($headers)).$row)->getFont()->setBold(true);
                $row++;
            }
        }

        // Format number columns
        $firstNumCol = $parTiers ? 'E' : 'D';
        $lastCol = chr(64 + count($headers));
        if ($row > 2) {
            $sheet->getStyle($firstNumCol.'2:'.$lastCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    private function xlsxFluxTresorerie(RapportService $rapportService, int $exercice): Spreadsheet
    {
        $data = $rapportService->fluxTresorerie($exercice);
        $spreadsheet = new Spreadsheet;

        // Sheet 1: Synthèse + Mensuel
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthèse + Mensuel');

        $row = 1;
        $sheet->fromArray([['', 'Recettes', 'Dépenses', 'Solde (R-D)', 'Trésorerie cumulée']], null, 'A'.$row);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $row++;

        $sheet->fromArray([['Solde ouverture', null, null, null, $data['synthese']['solde_ouverture']]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
        $row++;

        foreach ($data['mensuel'] as $m) {
            $sheet->fromArray([[$m['mois'], $m['recettes'], $m['depenses'], $m['solde'], $m['cumul']]], null, 'A'.$row);
            $row++;
        }

        // Totaux
        $sheet->fromArray([['TOTAL', $data['synthese']['total_recettes'], $data['synthese']['total_depenses'], $data['synthese']['variation'], $data['synthese']['solde_theorique']]], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
        $sheet->getStyle('B2:E'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        // Sheet 2: Rapprochement
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Rapprochement');
        $row = 1;

        $sheet2->fromArray([['Élément', 'Montant']], null, 'A'.$row);
        $sheet2->getStyle('A1:B1')->getFont()->setBold(true);
        $row++;

        $sheet2->setCellValue('A'.$row, 'Solde théorique');
        $sheet2->setCellValue('B'.$row, $data['rapprochement']['solde_theorique']);
        $row++;

        $sheet2->setCellValue('A'.$row, 'Recettes non pointées ('.$data['rapprochement']['nb_recettes_non_pointees'].')');
        $sheet2->setCellValue('B'.$row, -$data['rapprochement']['recettes_non_pointees']);
        $row++;
        $sheet2->setCellValue('A'.$row, 'Dépenses non pointées ('.$data['rapprochement']['nb_depenses_non_pointees'].')');
        $sheet2->setCellValue('B'.$row, $data['rapprochement']['depenses_non_pointees']);
        $row++;

        $sheet2->setCellValue('A'.$row, 'Solde bancaire réel');
        $sheet2->setCellValue('B'.$row, $data['rapprochement']['solde_reel']);
        $sheet2->getStyle('A'.$row.':B'.$row)->getFont()->setBold(true);
        $sheet2->getStyle('B2:B'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        // Ouvrir sur le premier onglet
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private function xlsxAnalyse(string $mode, int $exercice, ExerciceService $exerciceService): Spreadsheet
    {
        // Re-use the AnalysePivot data logic
        $pivot = new AnalysePivot;
        $pivot->mode = $mode;
        $pivot->filterExercice = $exercice;

        $data = $mode === 'participants'
            ? $pivot->getParticipantsDataProperty()
            : $pivot->getFinancierDataProperty();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($mode === 'participants' ? 'Participants' : 'Analyse financière');

        if (empty($data)) {
            $sheet->setCellValue('A1', 'Aucune donnée');

            return $spreadsheet;
        }

        // Headers from first row keys
        $headers = array_keys($data[0]);
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);

        $row = 2;
        foreach ($data as $entry) {
            $sheet->fromArray([array_values($entry)], null, 'A'.$row);
            $row++;
        }

        // Format "Montant" or "Montant prévu" column as number
        $montantCol = null;
        foreach ($headers as $i => $h) {
            if (in_array($h, ['Montant', 'Montant prévu'], true)) {
                $montantCol = chr(65 + $i);
                break;
            }
        }
        if ($montantCol && $row > 2) {
            $sheet->getStyle($montantCol.'2:'.$montantCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    // ── PDF exports ───────────────────────────────────────────────────────────

    private function exportPdf(
        string $rapport,
        int $exercice,
        string $label,
        Request $request,
        RapportService $rapportService,
        ?Association $association,
        string $filename,
    ): Response {
        [$headerLogoBase64, $headerLogoMime] = $this->resolveAssociationLogo($association);

        $orientation = self::PDF_ORIENTATION[$rapport];
        $subtitle = 'Exercice '.$label;

        $viewData = match ($rapport) {
            'compte-resultat' => $this->pdfCompteResultatData($rapportService, $exercice, $label),
            'operations' => $this->pdfOperationsData($rapportService, $exercice, $request),
            'flux-tresorerie' => $this->pdfFluxTresorerieData($rapportService, $exercice),
        };

        if (isset($viewData['subtitle'])) {
            $subtitle = $viewData['subtitle'];
        }

        $appLogoPath = public_path('images/agora-gestion.svg');
        $appLogoBase64 = file_exists($appLogoPath) ? base64_encode(file_get_contents($appLogoPath)) : null;

        $data = array_merge($viewData, [
            'title' => self::TITLES[$rapport],
            'subtitle' => $subtitle,
            'association' => $association,
            'headerLogoBase64' => $headerLogoBase64,
            'headerLogoMime' => $headerLogoMime,
            'appLogoBase64' => $appLogoBase64,
            // Reports never use a footer association logo (the header has it).
            'footerLogoBase64' => null,
            'footerLogoMime' => null,
        ]);

        $view = 'pdf.rapport-'.str_replace('-', '-', $rapport);

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', $orientation);

        PdfFooterRenderer::render($pdf);

        return $pdf->stream($filename);
    }

    private function pdfCompteResultatData(RapportService $rapportService, int $exercice, string $label): array
    {
        $data = $rapportService->compteDeResultat($exercice);
        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;

        $provisionService = app(ProvisionService::class);
        $provisions = $provisionService->provisionsExercice($exercice);
        $provisionsN1 = $provisionService->provisionsExercice($exercice - 1);
        $extournes = $provisionService->extournesExercice($exercice);
        $extournesN1 = $provisionService->extournesExercice($exercice - 1);
        $totalProvisions = $provisionService->totalProvisions($exercice);
        $totalProvisionsN1 = $provisionService->totalProvisions($exercice - 1);
        $totalExtournes = $provisionService->totalExtournes($exercice);
        $totalExtournesN1 = $provisionService->totalExtournes($exercice - 1);

        $resultatBrut = $resultatCourant + $totalExtournes;
        $resultatBrutN1 = $resultatCourantN1 + $totalExtournesN1;
        $resultatNet = $resultatBrut + $totalProvisions;
        $resultatNetN1 = $resultatBrutN1 + $totalProvisionsN1;

        return [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $label,
            'labelN1' => ($exercice - 1).'-'.$exercice,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'totalChargesN1' => $totalChargesN1,
            'totalProduitsN1' => $totalProduitsN1,
            'provisions' => $provisions,
            'provisionsN1' => $provisionsN1,
            'extournes' => $extournes,
            'extournesN1' => $extournesN1,
            'totalProvisions' => $totalProvisions,
            'totalProvisionsN1' => $totalProvisionsN1,
            'totalExtournes' => $totalExtournes,
            'totalExtournesN1' => $totalExtournesN1,
            'resultatBrut' => $resultatBrut,
            'resultatBrutN1' => $resultatBrutN1,
            'resultatNet' => $resultatNet,
            'resultatNetN1' => $resultatNetN1,
        ];
    }

    private function pdfOperationsData(RapportService $rapportService, int $exercice, Request $request): array
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');
        $previsionnel = $request->boolean('prev');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers, $previsionnel);
        $seances = $data['seances'] ?? [];

        $totalCharges = $parSeances
            ? collect($data['charges'])->sum('total')
            : collect($data['charges'])->sum('montant');
        $totalProduits = $parSeances
            ? collect($data['produits'])->sum('total')
            : collect($data['produits'])->sum('montant');

        return [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'previsionsCharges' => $data['previsions_charges'] ?? [],
            'previsionsProduits' => $data['previsions_produits'] ?? [],
            'seances' => $seances,
            'parSeances' => $parSeances,
            'parTiers' => $parTiers,
            'previsionnel' => $previsionnel,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
        ];
    }

    private function pdfFluxTresorerieData(RapportService $rapportService, int $exercice): array
    {
        return $rapportService->fluxTresorerie($exercice);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function autoSizeColumns(Spreadsheet $spreadsheet): void
    {
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $highestCol = $sheet->getHighestColumn();
            $col = 'A';
            while ($col !== $highestCol) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
                $col++;
            }
            $sheet->getColumnDimension($highestCol)->setAutoSize(true);
        }
    }
}
