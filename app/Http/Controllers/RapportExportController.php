<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesLogos;
use App\Livewire\AnalysePivot;
use App\Models\Association;
use App\Services\ExerciceService;
use App\Services\RapportService;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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

        $association = Association::find(1);
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

        // Format number columns
        $sheet->getStyle('D2:G'.$row)->getNumberFormat()->setFormatCode('#,##0.00');

        return $spreadsheet;
    }

    private function xlsxOperations(RapportService $rapportService, int $exercice, Request $request): Spreadsheet
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('CR par opérations');

        $seances = $data['seances'] ?? [];
        $row = 1;

        // Header row
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
            $headers[] = 'Montant';
        }
        $sheet->fromArray([$headers], null, 'A'.$row);
        $sheet->getStyle('A1:'.chr(64 + count($headers)).'1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges']], ['Produit', $data['produits']]] as [$type, $sections]) {
            foreach ($sections as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
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
                        foreach ($seances as $s) {
                            $values[] = (float) ($sc['seances'][$s] ?? 0);
                        }
                        $values[] = (float) ($sc['total'] ?? 0);
                    } else {
                        $values[] = (float) ($sc['montant'] ?? 0);
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
                    foreach ($seances as $s) {
                        $values[] = (float) ($cat['seances'][$s] ?? 0);
                    }
                    $values[] = (float) ($cat['total'] ?? 0);
                } else {
                    $values[] = (float) ($cat['montant'] ?? 0);
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

        foreach ($data['rapprochement']['comptes_systeme'] as $cs) {
            $sheet2->setCellValue('A'.$row, $cs['nom'].' ('.$cs['nb_ecritures'].' écr.)');
            $sheet2->setCellValue('B'.$row, -$cs['solde']);
            $row++;
        }

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

        return [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $label,
            'labelN1' => ($exercice - 1).'-'.$exercice,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet' => $totalProduitsN - $totalChargesN,
        ];
    }

    private function pdfOperationsData(RapportService $rapportService, int $exercice, Request $request): array
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers);
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
            'seances' => $seances,
            'parSeances' => $parSeances,
            'parTiers' => $parTiers,
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
