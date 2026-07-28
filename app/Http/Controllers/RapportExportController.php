<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesLogos;
use App\Livewire\AnalysePivot;
use App\Models\Association;
use App\Services\ExerciceService;
use App\Services\Rapports\BalanceComptableBuilder;
use App\Services\Rapports\GrandLivreBuilder;
use App\Services\Rapports\JournauxBuilder;
use App\Services\Rapports\ProjectionMatrix;
use App\Services\Rapports\VentilationFinanciereService;
use App\Services\RapportService;
use App\Support\CurrentAssociation;
use App\Support\PdfFooterRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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
        'balance' => ['xlsx', 'pdf'],
        'grand-livre' => ['xlsx', 'pdf'],
        'journaux' => ['xlsx', 'pdf'],
        'operations' => ['xlsx', 'pdf'],
        'flux-tresorerie' => ['xlsx', 'pdf'],
        'analyse-financier' => ['xlsx'],
        'analyse-participants' => ['xlsx'],
    ];

    /** PDF orientations */
    private const PDF_ORIENTATION = [
        'compte-resultat' => 'portrait',
        'balance' => 'landscape',
        'grand-livre' => 'landscape',
        'journaux' => 'landscape',
        'operations' => 'landscape',
        'flux-tresorerie' => 'portrait',
    ];

    /** Human-readable rapport names (for filenames and titles) */
    private const TITLES = [
        'compte-resultat' => 'Compte de resultat',
        'balance' => 'Balance comptable',
        'grand-livre' => 'Grand livre',
        'journaux' => 'Journaux',
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
            'pdf' => $this->exportPdf($rapport, $exercice, $label, $request, $rapportService, $exerciceService, $association, $filename),
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
            'compte-resultat' => $this->xlsxCompteResultat(
                $rapportService,
                $exercice,
                $label,
                $request->boolean('n1', true),
                $request->boolean('budget', true),
            ),
            'balance' => $this->xlsxBalance($request, $exercice, $exerciceService),
            'grand-livre' => $this->xlsxGrandLivre($request, $exercice, $exerciceService),
            'journaux' => $this->xlsxJournaux($request, $exercice, $exerciceService),
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

    private function xlsxCompteResultat(
        RapportService $rapportService,
        int $exercice,
        string $label,
        bool $compareN1 = true,
        bool $compareBudget = true,
    ): Spreadsheet {
        $data = $rapportService->compteDeResultat($exercice);

        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = (float) $totalProduitsN - (float) $totalChargesN;
        $resultatCourantN1 = (float) $totalProduitsN1 - (float) $totalChargesN1;

        [
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
        ] = $rapportService->compteDeResultatProvisions($exercice, $resultatCourant, $resultatCourantN1);

        $labelN1 = ($exercice - 1).'-'.$exercice;
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Compte de résultat');

        $row = 1;
        $sheet->fromArray([['Nature', 'Famille', 'Compte', $labelN1, $label, 'Budget', 'Écart']], null, 'A'.$row);
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $row++;

        foreach ([['Charge', $data['charges']], ['Produit', $data['produits']]] as [$type, $sections]) {
            foreach ($sections as $cat) {
                foreach ($cat['comptes'] as $sc) {
                    $ecart = ($sc['budget'] !== null && $sc['montant_n'] !== null)
                        ? (float) $sc['montant_n'] - (float) $sc['budget']
                        : null;
                    $sheet->fromArray([[
                        $type,
                        $cat['famille_nom'],
                        $sc['compte_nom'],
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
                    $cat['famille_nom'],
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

        // Blank separator row
        $row++;

        // Extournes section
        if ($extournes->isNotEmpty() || $extournesN1->isNotEmpty()) {
            $extournesN1Keyed = $extournesN1->keyBy(fn (array $e) => $e['libelle'].'|'.$e['compte_id']);
            $extournesNKeyed = $extournes->keyBy(fn (array $e) => $e['libelle'].'|'.$e['compte_id']);
            $allExtourneKeys = $extournesN1Keyed->keys()->merge($extournesNKeyed->keys())->unique();

            foreach ($allExtourneKeys as $key) {
                $eN = $extournesNKeyed->get($key);
                $eN1 = $extournesN1Keyed->get($key);
                $familleNom = $eN['famille_nom'] ?? $eN1['famille_nom'];
                $compteNom = $eN['compte_nom'] ?? $eN1['compte_nom'];
                $sheet->fromArray([[
                    'Extourne',
                    $familleNom,
                    $compteNom,
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
            $provisionsN1Keyed = $provisionsN1->keyBy(fn (array $p) => $p['libelle'].'|'.$p['compte_id']);
            $provisionsNKeyed = $provisions->keyBy(fn (array $p) => $p['libelle'].'|'.$p['compte_id']);
            $allProvisionKeys = $provisionsN1Keyed->keys()->merge($provisionsNKeyed->keys())->unique();

            foreach ($allProvisionKeys as $key) {
                $pN = $provisionsNKeyed->get($key);
                $pN1 = $provisionsN1Keyed->get($key);
                $familleNom = $pN['famille_nom'] ?? $pN1['famille_nom'];
                $compteNom = $pN['compte_nom'] ?? $pN1['compte_nom'];
                $sheet->fromArray([[
                    'Provision',
                    $familleNom,
                    $compteNom,
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

        // Colonnes : A Type | B Famille | C Compte | D N-1 | E N | F Budget | G Écart
        if (! $compareBudget) {
            $sheet->removeColumn('F', 2); // Budget + Écart
        }
        if (! $compareN1) {
            $sheet->removeColumn('D', 1); // N-1
        }

        return $spreadsheet;
    }

    private function xlsxBalance(Request $request, int $exercice, ExerciceService $exerciceService): Spreadsheet
    {
        $params = $this->balanceParams($request, $exercice, $exerciceService);
        $balance = app(BalanceComptableBuilder::class)->balance(
            $params['date_debut'],
            $params['date_fin'],
            $params['prefixes'],
            $params['uniquement_non_soldes'],
            $params['detail_par_tiers'],
        );

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Balance');

        $headers = $this->balanceHeaders($params['colonnes']);
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', 'Balance comptable');
        $sheet->mergeCells('A1:'.$lastCol.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray([['Période', $this->periodeLabel($params['date_debut'], $params['date_fin'])]], null, 'A2');
        $sheet->fromArray([['Comptes', $params['comptes']]], null, 'A3');
        $sheet->getStyle('A2:A3')->getFont()->setBold(true);

        $headerRow = 5;
        $sheet->fromArray([$headers], null, 'A'.$headerRow);
        $sheet->getStyle('A'.$headerRow.':'.$lastCol.$headerRow)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '3D5473'],
            ],
        ]);

        $row = $headerRow + 1;

        foreach ($balance['lignes'] as $ligne) {
            $sheet->fromArray([$this->balanceRow($ligne, $params['colonnes'])], null, 'A'.$row);
            $sheet->setCellValueExplicit('A'.$row, (string) $ligne['numero_compte'], DataType::TYPE_STRING);
            $row++;
        }

        if ($balance['lignes'] !== []) {
            $totalRow = $this->balanceTotalRow($balance, $params['colonnes']);
            $sheet->fromArray([$totalRow], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':'.$lastCol.$row)->applyFromArray([
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '5A7FA8'],
                ],
            ]);
        }

        if ($row > $headerRow + 1) {
            $firstAmountCol = Coordinate::stringFromColumnIndex(4);
            $sheet->getStyle($firstAmountCol.($headerRow + 1).':'.$lastCol.$row)
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    private function xlsxGrandLivre(Request $request, int $exercice, ExerciceService $exerciceService): Spreadsheet
    {
        $params = $this->grandLivreParams($request, $exercice, $exerciceService);
        $grandLivre = app(GrandLivreBuilder::class)->grandLivre(
            $params['date_debut'],
            $params['date_fin'],
            $params['prefixes'],
            $params['uniquement_non_soldes'],
            $params['uniquement_non_lettrees'],
        );

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Grand livre');

        $headers = ['Compte', 'Intitulé', 'Tiers', 'Date', 'Journal', 'Pièce', 'Libellé', 'Règlement', 'Lettrage', 'Débit', 'Crédit', 'Solde'];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', 'Grand livre');
        $sheet->mergeCells('A1:'.$lastCol.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray([['Période', $this->periodeLabel($params['date_debut'], $params['date_fin'])]], null, 'A2');
        $sheet->fromArray([['Comptes', $params['comptes']]], null, 'A3');
        $sheet->getStyle('A2:A3')->getFont()->setBold(true);

        $headerRow = 5;
        $sheet->fromArray([$headers], null, 'A'.$headerRow);
        $this->styleEnteteXlsx($sheet, 'A'.$headerRow.':'.$lastCol.$headerRow);

        $row = $headerRow + 1;

        foreach ($grandLivre['comptes'] as $compte) {
            // Solde d'ouverture, puis chaque écriture — chaque ligne porte le
            // compte et le tiers pour rester exploitable après un tri Excel.
            $sheet->fromArray([[
                $compte['numero_compte'],
                $compte['intitule_compte'],
                $compte['tiers'],
                null, null, null, 'Solde ouverture', null, null, null, null,
                $this->euros((int) $compte['solde_ouverture_centimes']),
            ]], null, 'A'.$row);
            $sheet->setCellValueExplicit('A'.$row, (string) $compte['numero_compte'], DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
            $row++;

            foreach ($compte['lignes'] as $ligne) {
                $sheet->fromArray([[
                    $compte['numero_compte'],
                    $compte['intitule_compte'],
                    $compte['tiers'],
                    $ligne['date'],
                    $ligne['journal'],
                    $ligne['numero_piece'] ?? $ligne['reference'],
                    $ligne['libelle'],
                    $ligne['mode_paiement'],
                    $ligne['lettrage_code'],
                    $this->euros((int) $ligne['debit_centimes']),
                    $this->euros((int) $ligne['credit_centimes']),
                    $this->euros((int) $ligne['solde_progressif_centimes']),
                ]], null, 'A'.$row);
                $sheet->setCellValueExplicit('A'.$row, (string) $compte['numero_compte'], DataType::TYPE_STRING);
                $row++;
            }

            $sheet->fromArray([[
                $compte['numero_compte'],
                $compte['intitule_compte'],
                $compte['tiers'],
                null, null, null, 'TOTAL', null, null,
                $this->euros((int) $compte['mouvement_debit_centimes']),
                $this->euros((int) $compte['mouvement_credit_centimes']),
                $this->euros((int) $compte['solde_fin_centimes']),
            ]], null, 'A'.$row);
            $sheet->setCellValueExplicit('A'.$row, (string) $compte['numero_compte'], DataType::TYPE_STRING);
            $this->styleTotalXlsx($sheet, 'A'.$row.':'.$lastCol.$row);
            $row++;
        }

        if ($row > $headerRow + 1) {
            $sheet->getStyle('J'.($headerRow + 1).':'.$lastCol.($row - 1))
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    /**
     * Journaux — export à plat : chaque ligne porte l'intégralité du contexte
     * (date, pièce, libellé, compte, règlement, lettrage), de sorte que le
     * fichier reste exploitable après un tri ou un filtre Excel. Les totaux
     * sont posés par journal, pas par pièce.
     */
    private function xlsxJournaux(Request $request, int $exercice, ExerciceService $exerciceService): Spreadsheet
    {
        $params = $this->journauxParams($request, $exercice, $exerciceService);
        $resultat = app(JournauxBuilder::class)->journaux(
            $params['date_debut'],
            $params['date_fin'],
            $params['journaux'],
        );

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Journaux');

        $headers = ['Journal', 'Date', 'Pièce', 'Libellé', 'Compte', 'Intitulé', 'Tiers', 'Règlement', 'Lettrage', 'Débit', 'Crédit'];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', 'Journaux');
        $sheet->mergeCells('A1:'.$lastCol.'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray([['Période', $this->periodeLabel($params['date_debut'], $params['date_fin'])]], null, 'A2');
        $sheet->fromArray([['Journaux', $params['journaux'] === [] ? 'Tous' : implode(', ', $params['journaux'])]], null, 'A3');
        $sheet->getStyle('A2:A3')->getFont()->setBold(true);

        $headerRow = 5;
        $sheet->fromArray([$headers], null, 'A'.$headerRow);
        $this->styleEnteteXlsx($sheet, 'A'.$headerRow.':'.$lastCol.$headerRow);

        $row = $headerRow + 1;

        foreach ($resultat['journaux'] as $bloc) {
            foreach ($bloc['pieces'] as $piece) {
                foreach ($piece['lignes'] as $ligne) {
                    $sheet->fromArray([[
                        $bloc['libelle'],
                        $piece['date'],
                        $piece['numero_piece'] ?? $piece['reference'],
                        $piece['libelle'],
                        $ligne['numero_compte'],
                        $ligne['intitule_compte'],
                        $ligne['tiers'],
                        $piece['mode_paiement'],
                        $ligne['lettrage_code'],
                        $this->euros((int) $ligne['debit_centimes']),
                        $this->euros((int) $ligne['credit_centimes']),
                    ]], null, 'A'.$row);
                    $sheet->setCellValueExplicit('E'.$row, (string) $ligne['numero_compte'], DataType::TYPE_STRING);
                    $row++;
                }
            }

            $sheet->fromArray([[
                'TOTAL '.$bloc['libelle'],
                null, null, null, null, null, null, null, null,
                $this->euros((int) $bloc['debit_centimes']),
                $this->euros((int) $bloc['credit_centimes']),
            ]], null, 'A'.$row);
            $this->styleTotalXlsx($sheet, 'A'.$row.':'.$lastCol.$row);
            $row++;
        }

        if ($resultat['journaux'] !== []) {
            $sheet->fromArray([[
                'TOTAL GÉNÉRAL',
                null, null, null, null, null, null, null, null,
                $this->euros((int) $resultat['totaux']['debit_centimes']),
                $this->euros((int) $resultat['totaux']['credit_centimes']),
            ]], null, 'A'.$row);
            $this->styleTotalXlsx($sheet, 'A'.$row.':'.$lastCol.$row);
            $row++;
        }

        if ($row > $headerRow + 1) {
            $sheet->getStyle('J'.($headerRow + 1).':'.$lastCol.($row - 1))
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }

    private function styleEnteteXlsx(Worksheet $sheet, string $plage): void
    {
        $sheet->getStyle($plage)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3D5473']],
        ]);
    }

    private function styleTotalXlsx(Worksheet $sheet, string $plage): void
    {
        $sheet->getStyle($plage)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5A7FA8']],
        ]);
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
            'projection' => 'Projection',
            default => 'Réalisé',
        };
        $sheet->setTitle('CR par opérations ('.$modeLabel.')');

        $seances = $data['seances'] ?? [];
        $operationNames = $data['operation_names'] ?? [];
        $row = 1;

        $sheet->setCellValue('A'.$row, 'Mode : '.$modeLabel);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setItalic(true)->setSize(10);
        $row++;

        // Build flat previsions lookup: sc_id => { montant, seances: {num => float}, operations?: {op_id => float} }
        // Only populated when $previsionnel is true.
        $buildPrevIdx = function (array $hierarchy) use ($parOperations): array {
            $idx = [];
            foreach ($hierarchy as $cat) {
                foreach ($cat['comptes'] as $sc) {
                    $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);
                    $entry = [
                        'montant' => (float) ($sc['montant'] ?? 0),
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

        // ProjectionMatrix objects from Builder
        /** @var ProjectionMatrix|null $projChargesMatrix */
        $projChargesMatrix = $data['proj_charges'] ?? null;
        /** @var ProjectionMatrix|null $projProduitsMatrix */
        $projProduitsMatrix = $data['proj_produits'] ?? null;

        // Resolve per-section ProjectionMatrix
        $projMatrixFor = fn (string $sectionLabel): ?ProjectionMatrix => $sectionLabel === 'DÉPENSES' ? $projChargesMatrix : $projProduitsMatrix;

        // Merge prevision-only is now done in the Builder — no need to do it here.

        $seancesParOperation = $data['seances_par_operation'] ?? [];
        $combinedMode = $parSeances && $parOperations;

        // ── combinedMode: 2-level header (op → séances) with merge cells ────────
        if ($combinedMode) {
            $labelCols = ['Type', 'Famille', 'Compte'];
            if ($parTiers) {
                $labelCols[] = 'Tiers';
            }
            $labelColCount = count($labelCols);

            $col = 1;
            foreach ($labelCols as $lbl) {
                $cell = Coordinate::stringFromColumnIndex($col);
                $sheet->setCellValue($cell.'1', $lbl);
                $sheet->mergeCells($cell.'1:'.$cell.'2');
                $col++;
            }

            foreach ($operationNames as $opId => $opNom) {
                $opSeances = $seancesParOperation[$opId] ?? [];
                $span = count($opSeances) + 1;
                $startCol = Coordinate::stringFromColumnIndex($col);
                $endCol = Coordinate::stringFromColumnIndex($col + $span - 1);
                $sheet->setCellValue($startCol.'1', $opNom);
                if ($span > 1) {
                    $sheet->mergeCells($startCol.'1:'.$endCol.'1');
                }
                foreach ($opSeances as $s) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).'2', $s === 0 ? 'H.S.' : 'S'.$s);
                    $col++;
                }
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col).'2', 'Tot.');
                $col++;
            }

            $totalColIdx = $col;
            $totalColLetter = Coordinate::stringFromColumnIndex($totalColIdx);
            $sheet->setCellValue($totalColLetter.'1', 'Total');
            $sheet->mergeCells($totalColLetter.'1:'.$totalColLetter.'2');
            $lastCol = $totalColLetter;

            $sheet->getStyle('A1:'.$lastCol.'2')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$lastCol.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row = 3;

            $sectionTotals = [];

            foreach ([['Charge', $data['charges'], $prevChargesIdx, 'DÉPENSES'], ['Produit', $data['produits'], $prevProduitsIdx, 'RECETTES']] as [$type, $sections, $prevIdx, $sectionLabel]) {
                $projMatrix = $projMatrixFor($sectionLabel);

                foreach ($sections as $cat) {
                    foreach ($cat['comptes'] as $sc) {
                        $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);

                        if ($parTiers && ! empty($sc['tiers'])) {
                            foreach ($sc['tiers'] as $t) {
                                $tId = (int) ($t['tiers_id'] ?? 0);
                                $tValues = [$type, $cat['famille_nom'], $sc['compte_nom'], $t['label']];
                                $projTSO = ($mode === 'projection' && $projMatrix) ? ($projMatrix->byScTiersSeanceOp($scId)[$tId] ?? []) : [];
                                foreach ($operationNames as $opId => $opName) {
                                    foreach ($seancesParOperation[$opId] ?? [] as $s) {
                                        $tValues[] = ($mode === 'projection' && $projMatrix) ? (float) ($projTSO[$s][$opId] ?? 0) : (float) ($t['seance_operations'][$s][$opId] ?? 0);
                                    }
                                    $tValues[] = ($mode === 'projection' && $projMatrix)
                                        ? (float) ($projMatrix->byScTiersOp($scId)[$tId][$opId] ?? 0)
                                        : (float) ($t['operations'][$opId] ?? 0);
                                }
                                $tValues[] = ($mode === 'projection' && $projMatrix)
                                    ? (float) ($projMatrix->byScTiers($scId)[$tId] ?? 0)
                                    : (float) ($t['montant'] ?? 0);
                                $sheet->fromArray([$tValues], null, 'A'.$row);
                                $row++;
                            }
                        }

                        $values = [$type, $cat['famille_nom'], $sc['compte_nom']];
                        if ($parTiers) {
                            $values[] = 'TOTAL';
                        }
                        foreach ($operationNames as $opId => $opName) {
                            foreach ($seancesParOperation[$opId] ?? [] as $s) {
                                $values[] = ($mode === 'projection' && $projMatrix) ? (float) ($projMatrix->byScSeanceOp()[$scId][$s][$opId] ?? 0) : (float) ($sc['seance_operations'][$s][$opId] ?? 0);
                            }
                            $values[] = ($mode === 'projection' && $projMatrix)
                                ? (float) ($projMatrix->byScOp()[$scId][$opId] ?? 0)
                                : (float) ($sc['operations'][$opId] ?? 0);
                        }
                        $values[] = ($mode === 'projection' && $projMatrix)
                            ? (float) ($projMatrix->bySc()[$scId] ?? 0)
                            : (float) ($sc['montant'] ?? 0);
                        $sheet->fromArray([$values], null, 'A'.$row);
                        if ($parTiers) {
                            $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
                        }
                        $row++;
                    }

                    $catValues = [$type, $cat['famille_nom'], 'TOTAL'];
                    if ($parTiers) {
                        $catValues[] = '';
                    }
                    $catId = (int) ($cat['famille_id'] ?? 0);
                    foreach ($operationNames as $opId => $opName) {
                        foreach ($seancesParOperation[$opId] ?? [] as $s) {
                            $catValues[] = ($mode === 'projection' && $projMatrix)
                                ? collect($cat['comptes'])->sum(fn ($__sc) => (float) ($projMatrix->byScSeanceOp()[(int) ($__sc['compte_id'] ?? 0)][$s][$opId] ?? 0))
                                : (float) ($cat['seance_operations'][$s][$opId] ?? 0);
                        }
                        $catValues[] = ($mode === 'projection' && $projMatrix)
                            ? (float) ($projMatrix->byCatOp()[$catId][$opId] ?? 0)
                            : (float) ($cat['operations'][$opId] ?? 0);
                    }
                    $catValues[] = ($mode === 'projection' && $projMatrix)
                        ? (float) ($projMatrix->byCat()[$catId] ?? 0)
                        : (float) ($cat['montant'] ?? 0);
                    $sheet->fromArray([$catValues], null, 'A'.$row);
                    $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
                    $row++;
                }

                $sectionValues = ['', '', 'TOTAL '.$sectionLabel];
                if ($parTiers) {
                    $sectionValues[] = '';
                }
                $totalSectionSeanceOps = [];
                foreach ($sections as $_cat) {
                    foreach ($_cat['seance_operations'] ?? [] as $s => $ops) {
                        foreach ($ops as $_opId => $m) {
                            $totalSectionSeanceOps[$s][$_opId] = ($totalSectionSeanceOps[$s][$_opId] ?? 0.0) + (float) $m;
                        }
                    }
                }
                foreach ($operationNames as $opId => $opName) {
                    foreach ($seancesParOperation[$opId] ?? [] as $s) {
                        $sectionValues[] = ($mode === 'projection' && $projMatrix) ? (float) ($projMatrix->bySeanceOp()[$s][$opId] ?? 0) : (float) ($totalSectionSeanceOps[$s][$opId] ?? 0);
                    }
                    $opTotal = ($mode === 'projection' && $projMatrix) ? (float) ($projMatrix->byOp()[$opId] ?? 0) : 0.0;
                    if (! ($mode === 'projection' && $projMatrix)) {
                        $opTotal = 0.0;
                        foreach ($sections as $_cat) {
                            $opTotal += (float) ($_cat['operations'][$opId] ?? 0);
                        }
                    }
                    $sectionValues[] = $opTotal;
                    $sectionTotals[$sectionLabel][$opId] = $opTotal;
                }
                $grandTotal = ($mode === 'projection' && $projMatrix) ? $projMatrix->total() : 0.0;
                if (! ($mode === 'projection' && $projMatrix)) {
                    $grandTotal = 0.0;
                    foreach ($sections as $_cat) {
                        $grandTotal += (float) ($_cat['montant'] ?? 0);
                    }
                }
                $sectionValues[] = $grandTotal;
                $sectionTotals[$sectionLabel]['_total'] = $grandTotal;
                $sheet->fromArray([$sectionValues], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
                $row++;
                $row++;
            }

            $resultatValues = ['', '', 'RÉSULTAT'];
            if ($parTiers) {
                $resultatValues[] = '';
            }
            foreach ($operationNames as $opId => $opName) {
                foreach ($seancesParOperation[$opId] ?? [] as $s) {
                    $resultatValues[] = '';
                }
                $recOp = (float) ($sectionTotals['RECETTES'][$opId] ?? 0.0);
                $depOp = (float) ($sectionTotals['DÉPENSES'][$opId] ?? 0.0);
                $resultatValues[] = $recOp - $depOp;
            }
            $recTotal = (float) ($sectionTotals['RECETTES']['_total'] ?? 0.0);
            $depTotal = (float) ($sectionTotals['DÉPENSES']['_total'] ?? 0.0);
            $resultatValues[] = $recTotal - $depTotal;
            $sheet->fromArray([$resultatValues], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);

            $firstNumCol = Coordinate::stringFromColumnIndex($labelColCount + 1);
            if ($row > 3) {
                $sheet->getStyle($firstNumCol.'3:'.$lastCol.($row))->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return $spreadsheet;
        }

        // ── parOperations: header and data rows ──────────────────────────────────
        if ($parOperations) {
            $labelCols = ['Type', 'Famille', 'Compte'];
            if ($parSeances) {
                $labelCols[] = 'Séance';
            }
            if ($parTiers) {
                $labelCols[] = 'Tiers';
            }
            $labelColCount = count($labelCols);

            $headers = $labelCols;
            foreach ($operationNames as $opName) {
                $headers[] = $opName;
            }
            $headers[] = 'Total';
            $sheet->fromArray([$headers], null, 'A1');
            $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);
            $row = 2;

            $sectionTotals = [];

            foreach ([['Charge', $data['charges'], $prevChargesIdx, 'DÉPENSES'], ['Produit', $data['produits'], $prevProduitsIdx, 'RECETTES']] as [$type, $sections, $prevIdx, $sectionLabel]) {
                foreach ($sections as $cat) {
                    foreach ($cat['comptes'] as $sc) {
                        $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);

                        // Séance sub-rows (combined mode)
                        if ($parSeances) {
                            /** @var ProjectionMatrix|null $projMatrix */
                            $projMatrix = $sectionLabel === 'DÉPENSES' ? $projChargesMatrix : $projProduitsMatrix;
                            foreach ($seances as $s) {
                                $sLabel = $s === 0 ? 'Hors séances' : 'S'.$s;
                                $sValues = [$type, $cat['famille_nom'], $sc['compte_nom'], $sLabel];
                                if ($parTiers) {
                                    $sValues[] = '';
                                }
                                foreach ($operationNames as $opId => $opName) {
                                    if ($mode === 'projection' && $projMatrix) {
                                        $sValues[] = (float) ($projMatrix->byScSeanceOp()[$scId][$s][$opId] ?? 0);
                                    } else {
                                        $sValues[] = 0.0;
                                    }
                                }
                                if ($mode === 'projection' && $projMatrix) {
                                    $sValues[] = (float) ($projMatrix->byScSeance()[$scId][$s] ?? 0);
                                } else {
                                    $sValues[] = (float) ($sc['seances'][$s] ?? 0);
                                }
                                $sheet->fromArray([$sValues], null, 'A'.$row);
                                $row++;
                            }
                        }

                        // Tiers rows (before SC subtotal)
                        if ($parTiers && ! empty($sc['tiers'])) {
                            $projMatrix = $projMatrixFor($sectionLabel);
                            foreach ($sc['tiers'] as $t) {
                                $tId = (int) ($t['tiers_id'] ?? 0);
                                $tValues = [$type, $cat['famille_nom'], $sc['compte_nom']];
                                if ($parSeances) {
                                    $tValues[] = '';
                                }
                                $tValues[] = $t['label'];
                                if ($mode === 'projection' && $projMatrix) {
                                    $projTOps = $projMatrix->byScTiersOp($scId)[$tId] ?? [];
                                    foreach ($operationNames as $opId => $opName) {
                                        $tValues[] = (float) ($projTOps[$opId] ?? 0);
                                    }
                                    $tValues[] = (float) ($projMatrix->byScTiers($scId)[$tId] ?? 0);
                                } else {
                                    foreach ($operationNames as $opId => $opName) {
                                        $tValues[] = (float) ($t['operations'][$opId] ?? 0);
                                    }
                                    $tValues[] = (float) ($t['montant'] ?? 0);
                                }
                                $sheet->fromArray([$tValues], null, 'A'.$row);
                                $row++;
                            }
                        }

                        // SC row (subtotal when parTiers or parSeances)
                        $values = [$type, $cat['famille_nom'], $sc['compte_nom']];
                        if ($parSeances) {
                            $values[] = 'TOTAL';
                        }
                        if ($parTiers) {
                            $values[] = $parSeances ? '' : 'TOTAL';
                        }

                        $projMatrix = $projMatrixFor($sectionLabel);
                        if ($mode === 'projection' && $projMatrix) {
                            foreach ($operationNames as $opId => $opName) {
                                $values[] = (float) ($projMatrix->byScOp()[$scId][$opId] ?? 0);
                            }
                            $values[] = (float) ($projMatrix->bySc()[$scId] ?? 0);
                        } else {
                            $total = 0.0;
                            foreach ($operationNames as $opId => $opName) {
                                $val = (float) ($sc['operations'][$opId] ?? 0);
                                $values[] = $val;
                                $total += $val;
                            }
                            $values[] = $total;
                        }

                        $sheet->fromArray([$values], null, 'A'.$row);
                        if ($parTiers || $parSeances) {
                            $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($values)).$row)->getFont()->setBold(true);
                        }
                        $row++;
                    }

                    // Category total row
                    $catValues = [$type, $cat['famille_nom'], 'TOTAL'];
                    if ($parSeances) {
                        $catValues[] = '';
                    }
                    if ($parTiers) {
                        $catValues[] = '';
                    }

                    $projMatrix = $projMatrixFor($sectionLabel);
                    if ($mode === 'projection' && $projMatrix) {
                        $catId = (int) ($cat['famille_id'] ?? 0);
                        foreach ($operationNames as $opId => $opName) {
                            $catValues[] = (float) ($projMatrix->byCatOp()[$catId][$opId] ?? 0);
                        }
                        $catValues[] = (float) ($projMatrix->byCat()[$catId] ?? 0);
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
                    $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($catValues)).$row)->getFont()->setBold(true);
                    $row++;
                }

                // Section total row (TOTAL DÉPENSES / TOTAL RECETTES)
                $sectionValues = ['', '', 'TOTAL '.$sectionLabel];
                if ($parSeances) {
                    $sectionValues[] = '';
                }
                if ($parTiers) {
                    $sectionValues[] = '';
                }

                $projMatrix = $projMatrixFor($sectionLabel);
                if ($mode === 'projection' && $projMatrix) {
                    foreach ($operationNames as $opId => $opName) {
                        $opTotal = (float) ($projMatrix->byOp()[$opId] ?? 0);
                        $sectionValues[] = $opTotal;
                        $sectionTotals[$sectionLabel][$opId] = $opTotal;
                    }
                    $grandTotal = $projMatrix->total();
                    $sectionValues[] = $grandTotal;
                    $sectionTotals[$sectionLabel]['_total'] = $grandTotal;
                } else {
                    foreach ($operationNames as $opId => $opName) {
                        $opTotal = 0.0;
                        foreach ($sections as $cat) {
                            $opTotal += (float) ($cat['operations'][$opId] ?? 0);
                        }
                        $sectionValues[] = $opTotal;
                        $sectionTotals[$sectionLabel][$opId] = $opTotal;
                    }
                    $grandTotal = 0.0;
                    foreach ($sections as $cat) {
                        $grandTotal += (float) ($cat['montant'] ?? 0);
                    }
                    $sectionValues[] = $grandTotal;
                    $sectionTotals[$sectionLabel]['_total'] = $grandTotal;
                }

                $sheet->fromArray([$sectionValues], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($sectionValues)).$row)->getFont()->setBold(true);
                $row++;
                $row++; // blank row between sections
            }

            // RÉSULTAT row
            $resultatValues = ['', '', 'RÉSULTAT'];
            if ($parSeances) {
                $resultatValues[] = '';
            }
            if ($parTiers) {
                $resultatValues[] = '';
            }

            foreach ($operationNames as $opId => $opName) {
                $recOp = (float) ($sectionTotals['RECETTES'][$opId] ?? 0.0);
                $depOp = (float) ($sectionTotals['DÉPENSES'][$opId] ?? 0.0);
                $resultatValues[] = $recOp - $depOp;
            }
            $recTotal = (float) ($sectionTotals['RECETTES']['_total'] ?? 0.0);
            $depTotal = (float) ($sectionTotals['DÉPENSES']['_total'] ?? 0.0);
            $resultatValues[] = $recTotal - $depTotal;

            $sheet->fromArray([$resultatValues], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($resultatValues)).$row)->getFont()->setBold(true);
            $row++;

            // Format number columns (D onwards)
            $firstNumCol = Coordinate::stringFromColumnIndex($labelColCount + 1);
            $lastCol = Coordinate::stringFromColumnIndex($labelColCount + count($operationNames) + 1);
            if ($row > 3) {
                $sheet->getStyle($firstNumCol.'2:'.$lastCol.($row - 1))->getNumberFormat()->setFormatCode('#,##0.00');
            }

            return $spreadsheet;
        }

        // ── Standard (non-parOperations) header ──────────────────────────────────
        if ($parSeances) {
            $headers = ['Type', 'Famille', 'Compte'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            foreach ($seances as $s) {
                $headers[] = $s === 0 ? 'Hors séances' : 'S'.$s;
            }
            $headers[] = 'Total';
        } else {
            $headers = ['Type', 'Famille', 'Compte'];
            if ($parTiers) {
                $headers[] = 'Tiers';
            }
            if ($mode === 'projection') {
                $headers[] = 'Projeté';
            } else {
                $headers[] = 'Montant';
            }
        }
        $sheet->fromArray([$headers], null, 'A'.$row);
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);
        $row++;

        $sectionTotals = [];
        $lastCol = Coordinate::stringFromColumnIndex(count($headers));

        foreach ([['Charge', $data['charges'], $prevChargesIdx, 'DÉPENSES'], ['Produit', $data['produits'], $prevProduitsIdx, 'RECETTES']] as [$type, $sections, $prevIdx, $sectionLabel]) {
            foreach ($sections as $cat) {
                foreach ($cat['comptes'] as $sc) {
                    $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);

                    if ($parTiers && ! empty($sc['tiers'])) {
                        $projMatrix = $projMatrixFor($sectionLabel);
                        foreach ($sc['tiers'] as $t) {
                            $tId = (int) ($t['tiers_id'] ?? 0);
                            $values = [$type, $cat['famille_nom'], $sc['compte_nom'], $t['label']];
                            if ($parSeances) {
                                if ($mode === 'projection' && $projMatrix) {
                                    $projTSeances = $projMatrix->byScTiersSeance($scId)[$tId] ?? [];
                                    foreach ($seances as $s) {
                                        $values[] = (float) ($projTSeances[$s] ?? 0);
                                    }
                                    $values[] = (float) ($projMatrix->byScTiers($scId)[$tId] ?? 0);
                                } else {
                                    foreach ($seances as $s) {
                                        $values[] = (float) ($t['seances'][$s] ?? 0);
                                    }
                                    $values[] = (float) ($t['montant'] ?? 0);
                                }
                            } else {
                                if ($mode === 'projection' && $projMatrix) {
                                    $values[] = (float) ($projMatrix->byScTiers($scId)[$tId] ?? 0);
                                } else {
                                    $values[] = (float) ($t['montant'] ?? 0);
                                }
                            }
                            $sheet->fromArray([$values], null, 'A'.$row);
                            $row++;
                        }
                    }
                    // Sous-total du compte
                    $values = [$type, $cat['famille_nom'], $sc['compte_nom']];
                    if ($parTiers) {
                        $values[] = 'TOTAL';
                    }
                    $projMatrix = $projMatrixFor($sectionLabel);
                    if ($parSeances) {
                        if ($mode === 'projection' && $projMatrix) {
                            foreach ($seances as $s) {
                                $values[] = (float) ($projMatrix->byScSeance()[$scId][$s] ?? 0);
                            }
                            $values[] = (float) ($projMatrix->bySc()[$scId] ?? 0);
                        } else {
                            foreach ($seances as $s) {
                                $values[] = (float) ($sc['seances'][$s] ?? 0);
                            }
                            $values[] = (float) ($sc['montant'] ?? 0);
                        }
                    } else {
                        if ($mode === 'projection' && $projMatrix) {
                            $values[] = (float) ($projMatrix->bySc()[$scId] ?? 0);
                        } else {
                            $values[] = (float) ($sc['montant'] ?? 0);
                        }
                    }
                    $sheet->fromArray([$values], null, 'A'.$row);
                    if ($parTiers) {
                        $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
                    }
                    $row++;
                }
                // Category total row
                $values = [$type, $cat['famille_nom'], 'TOTAL'];
                if ($parTiers) {
                    $values[] = '';
                }
                $catId = (int) ($cat['famille_id'] ?? 0);
                $projMatrix = $projMatrixFor($sectionLabel);
                if ($parSeances) {
                    if ($mode === 'projection' && $projMatrix) {
                        foreach ($seances as $s) {
                            $catSeanceProjected = 0.0;
                            foreach ($cat['comptes'] as $sc) {
                                $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);
                                $catSeanceProjected += (float) ($projMatrix->byScSeance()[$scId][$s] ?? 0);
                            }
                            $values[] = $catSeanceProjected;
                        }
                        $values[] = (float) ($projMatrix->byCat()[$catId] ?? 0);
                    } else {
                        foreach ($seances as $s) {
                            $values[] = (float) ($cat['seances'][$s] ?? 0);
                        }
                        $values[] = (float) ($cat['montant'] ?? 0);
                    }
                } else {
                    if ($mode === 'projection' && $projMatrix) {
                        $values[] = (float) ($projMatrix->byCat()[$catId] ?? 0);
                    } else {
                        $values[] = (float) ($cat['montant'] ?? 0);
                    }
                }
                $sheet->fromArray([$values], null, 'A'.$row);
                $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
                $row++;
            }

            // Section total row (TOTAL DÉPENSES / TOTAL RECETTES)
            $secValues = ['', '', 'TOTAL '.$sectionLabel];
            if ($parTiers) {
                $secValues[] = '';
            }

            $projMatrix = $projMatrixFor($sectionLabel);
            if ($parSeances) {
                if ($mode === 'projection' && $projMatrix) {
                    $secTotal = $projMatrix->total();
                    foreach ($seances as $s) {
                        $seanceTotal = 0.0;
                        foreach ($sections as $cat) {
                            foreach ($cat['comptes'] as $sc) {
                                $scId = (int) ($sc['compte_id'] ?? $sc['id'] ?? 0);
                                $seanceTotal += (float) ($projMatrix->byScSeance()[$scId][$s] ?? 0);
                            }
                        }
                        $secValues[] = $seanceTotal;
                    }
                    $secValues[] = $secTotal;
                } else {
                    $secTotal = 0.0;
                    foreach ($seances as $s) {
                        $seanceTotal = 0.0;
                        foreach ($sections as $cat) {
                            $seanceTotal += (float) ($cat['seances'][$s] ?? 0);
                        }
                        $secValues[] = $seanceTotal;
                    }
                    foreach ($sections as $cat) {
                        $secTotal += (float) ($cat['montant'] ?? 0);
                    }
                    $secValues[] = $secTotal;
                }
            } else {
                if ($mode === 'projection' && $projMatrix) {
                    $secTotal = $projMatrix->total();
                } else {
                    $secTotal = 0.0;
                    foreach ($sections as $cat) {
                        $secTotal += (float) ($cat['montant'] ?? 0);
                    }
                }
                $secValues[] = $secTotal;
            }
            $sectionTotals[$sectionLabel] = $secTotal;

            $sheet->fromArray([$secValues], null, 'A'.$row);
            $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
            $row++;
            $row++; // blank row between sections
        }

        // RÉSULTAT row
        $resultatValues = ['', '', 'RÉSULTAT'];
        if ($parTiers) {
            $resultatValues[] = '';
        }
        $recTotal = $sectionTotals['RECETTES'] ?? 0.0;
        $depTotal = $sectionTotals['DÉPENSES'] ?? 0.0;
        if ($parSeances) {
            foreach ($seances as $s) {
                $resultatValues[] = '';
            }
        }
        $resultatValues[] = $recTotal - $depTotal;
        $sheet->fromArray([$resultatValues], null, 'A'.$row);
        $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFont()->setBold(true);
        $row++;

        // Format number columns
        $firstNumCol = $parTiers ? 'E' : 'D';
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
        if ($mode === 'participants') {
            // L'onglet participants n'est pas (encore) extrait dans un service dédié.
            $pivot = new AnalysePivot;
            $pivot->mode = $mode;
            $pivot->filterExercice = $exercice;
            $data = $pivot->getParticipantsDataProperty();
        } else {
            // Financier : même source plate que l'écran Analyse (montant signé + éclatement).
            $data = app(VentilationFinanciereService::class)->pourExercice($exercice);
        }

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
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);

        $row = 2;
        foreach ($data as $entry) {
            $sheet->fromArray([array_values($entry)], null, 'A'.$row);
            $row++;
        }

        // Format "Montant" or "Montant prévu" column as number
        $montantCol = null;
        foreach ($headers as $i => $h) {
            if (in_array($h, ['Montant', 'Montant prévu'], true)) {
                $montantCol = Coordinate::stringFromColumnIndex($i + 1);
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
        ExerciceService $exerciceService,
        ?Association $association,
        string $filename,
    ): Response {
        [$headerLogoBase64, $headerLogoMime] = $this->resolveAssociationLogo($association);

        $orientation = self::PDF_ORIENTATION[$rapport];
        $subtitle = 'Exercice '.$label;

        $viewData = match ($rapport) {
            'compte-resultat' => $this->pdfCompteResultatData($rapportService, $exercice, $label, $request),
            'balance' => $this->pdfBalanceData($request, $exercice, $exerciceService),
            'grand-livre' => $this->pdfGrandLivreData($request, $exercice, $exerciceService),
            'journaux' => $this->pdfJournauxData($request, $exercice, $exerciceService),
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

    private function pdfCompteResultatData(RapportService $rapportService, int $exercice, string $label, Request $request): array
    {
        $data = $rapportService->compteDeResultat($exercice);
        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $totalChargesN1 = collect($data['charges'])->sum('montant_n1');
        $totalProduitsN1 = collect($data['produits'])->sum('montant_n1');
        $resultatCourant = $totalProduitsN - $totalChargesN;
        $resultatCourantN1 = $totalProduitsN1 - $totalChargesN1;

        [
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
        ] = $rapportService->compteDeResultatProvisions($exercice, (float) $resultatCourant, (float) $resultatCourantN1);

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
            'compareN1' => $request->boolean('n1', true),
            'compareBudget' => $request->boolean('budget', true),
        ];
    }

    private function pdfOperationsData(RapportService $rapportService, int $exercice, Request $request): array
    {
        $operationIds = array_map('intval', (array) $request->query('ops', []));
        $parSeances = $request->boolean('seances');
        $parTiers = $request->boolean('tiers');
        $mode = (string) $request->query('mode', 'realise');
        $previsionnel = $mode !== 'realise';
        $parOperations = $request->boolean('parops');

        $data = $rapportService->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers, $previsionnel, $parOperations);
        $seances = $data['seances'] ?? [];
        $operationNames = $data['operation_names'] ?? [];
        $seancesParOperation = $data['seances_par_operation'] ?? [];
        /** @var ProjectionMatrix|null $projChargesM */
        $projChargesM = $data['proj_charges'] ?? null;
        /** @var ProjectionMatrix|null $projProduitsM */
        $projProduitsM = $data['proj_produits'] ?? null;

        if ($mode === 'projection' && $projChargesM !== null) {
            $totalCharges = $projChargesM->total();
            $totalProduits = $projProduitsM->total();
        } else {
            $totalCharges = collect($data['charges'])->sum('montant');
            $totalProduits = collect($data['produits'])->sum('montant');
        }

        $modeLabel = match ($mode) {
            'projection' => 'Projection',
            default => 'Réalisé',
        };

        return [
            'subtitle' => 'Mode : '.$modeLabel,
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'previsionsCharges' => $data['previsions_charges'] ?? [],
            'previsionsProduits' => $data['previsions_produits'] ?? [],
            'seances' => $seances,
            'seancesParOperation' => $seancesParOperation,
            'parSeances' => $parSeances,
            'parTiers' => $parTiers,
            'previsionnel' => $previsionnel,
            'mode' => $mode,
            'parOperations' => $parOperations,
            'operationNames' => $operationNames,
            'projCharges' => $projChargesM,
            'projProduits' => $projProduitsM,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
        ];
    }

    private function pdfFluxTresorerieData(RapportService $rapportService, int $exercice): array
    {
        return $rapportService->fluxTresorerie($exercice);
    }

    private function pdfBalanceData(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $params = $this->balanceParams($request, $exercice, $exerciceService);
        $balance = app(BalanceComptableBuilder::class)->balance(
            $params['date_debut'],
            $params['date_fin'],
            $params['prefixes'],
            $params['uniquement_non_soldes'],
            $params['detail_par_tiers'],
        );

        return [
            'subtitle' => $this->periodeLabel($params['date_debut'], $params['date_fin']),
            'balance' => $balance,
            'colonnes' => $params['colonnes'],
            'dateDebut' => $params['date_debut'],
            'dateFin' => $params['date_fin'],
            'comptes' => $params['comptes'],
        ];
    }

    private function pdfGrandLivreData(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $params = $this->grandLivreParams($request, $exercice, $exerciceService);

        return [
            'subtitle' => $this->periodeLabel($params['date_debut'], $params['date_fin']),
            'grandLivre' => app(GrandLivreBuilder::class)->grandLivre(
                $params['date_debut'],
                $params['date_fin'],
                $params['prefixes'],
                $params['uniquement_non_soldes'],
                $params['uniquement_non_lettrees'],
            ),
            'comptes' => $params['comptes'],
            'afficherModeReglement' => $params['mode_reglement'],
        ];
    }

    private function pdfJournauxData(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $params = $this->journauxParams($request, $exercice, $exerciceService);

        return [
            'subtitle' => $this->periodeLabel($params['date_debut'], $params['date_fin']),
            'journal' => app(JournauxBuilder::class)->journaux(
                $params['date_debut'],
                $params['date_fin'],
                $params['journaux'],
            ),
            'afficherModeReglement' => $params['mode_reglement'],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array{date_debut: string, date_fin: string, comptes: string, prefixes: array<int, string>, uniquement_non_soldes: bool, uniquement_non_lettrees: bool, mode_reglement: bool}
     */
    private function grandLivreParams(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $range = $exerciceService->dateRange($exercice);
        $comptes = trim((string) $request->query('comptes', '1,2,3,4,5,6,7'));

        if ($comptes === '') {
            $comptes = '1,2,3,4,5,6,7';
        }

        return [
            'date_debut' => (string) $request->query('du', $range['start']->toDateString()),
            'date_fin' => (string) $request->query('au', $range['end']->toDateString()),
            'comptes' => $comptes,
            'prefixes' => $this->balancePrefixes($comptes),
            'uniquement_non_soldes' => $request->boolean('non_soldes'),
            'uniquement_non_lettrees' => $request->boolean('non_lettrees'),
            'mode_reglement' => $request->boolean('mode_reglement'),
        ];
    }

    /**
     * @return array{date_debut: string, date_fin: string, journaux: array<int, string>, mode_reglement: bool}
     */
    private function journauxParams(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $range = $exerciceService->dateRange($exercice);

        return [
            'date_debut' => (string) $request->query('du', $range['start']->toDateString()),
            'date_fin' => (string) $request->query('au', $range['end']->toDateString()),
            'journaux' => array_values(array_filter(
                array_map('strval', (array) $request->query('journaux', [])),
                static fn (string $code): bool => $code !== '',
            )),
            'mode_reglement' => $request->boolean('mode_reglement'),
        ];
    }

    /**
     * @return array{date_debut: string, date_fin: string, comptes: string, prefixes: array<int, string>, colonnes: int}
     */
    private function balanceParams(Request $request, int $exercice, ExerciceService $exerciceService): array
    {
        $range = $exerciceService->dateRange($exercice);
        $comptes = trim((string) $request->query('comptes', '1,2,3,4,5,6,7'));

        if ($comptes === '') {
            $comptes = '1,2,3,4,5,6,7';
        }

        return [
            'date_debut' => (string) $request->query('du', $range['start']->toDateString()),
            'date_fin' => (string) $request->query('au', $range['end']->toDateString()),
            'comptes' => $comptes,
            'prefixes' => $this->balancePrefixes($comptes),
            'colonnes' => $this->balanceColonnes($request->integer('colonnes', 6)),
            'uniquement_non_soldes' => $request->boolean('non_soldes'),
            'detail_par_tiers' => $request->boolean('detail_tiers'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function balancePrefixes(string $comptes): array
    {
        return collect(preg_split('/[,\s;]+/', $comptes) ?: [])
            ->map(fn (string $prefixe): string => trim($prefixe))
            ->filter(fn (string $prefixe): bool => $prefixe !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function balanceColonnes(int $colonnes): int
    {
        return in_array($colonnes, [2, 4, 6], true) ? $colonnes : 6;
    }

    /**
     * @return list<string>
     */
    private function balanceHeaders(int $colonnes): array
    {
        $headers = ['Compte', 'Intitulé', 'Tiers'];

        if ($colonnes >= 4) {
            $headers[] = 'Ouverture débit';
            $headers[] = 'Ouverture crédit';
        }

        if ($colonnes === 6) {
            $headers[] = 'Mouvement débit';
            $headers[] = 'Mouvement crédit';
        }

        $headers[] = 'Solde final débit';
        $headers[] = 'Solde final crédit';

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return list<string|float|null>
     */
    private function balanceRow(array $ligne, int $colonnes): array
    {
        $row = [
            (string) $ligne['numero_compte'],
            (string) $ligne['intitule_compte'],
            $ligne['tiers'] !== null ? (string) $ligne['tiers'] : null,
        ];

        if ($colonnes >= 4) {
            $row[] = $this->euros((int) $ligne['solde_ouverture_debit_centimes']);
            $row[] = $this->euros((int) $ligne['solde_ouverture_credit_centimes']);
        }

        if ($colonnes === 6) {
            $row[] = $this->euros((int) $ligne['mouvement_debit_centimes']);
            $row[] = $this->euros((int) $ligne['mouvement_credit_centimes']);
        }

        $row[] = $this->euros((int) $ligne['solde_fin_debit_centimes']);
        $row[] = $this->euros((int) $ligne['solde_fin_credit_centimes']);

        return $row;
    }

    /**
     * @param  array{lignes: list<array<string, mixed>>, totaux: array<string, int>}  $balance
     * @return list<string|float|null>
     */
    private function balanceTotalRow(array $balance, int $colonnes): array
    {
        $lignes = $balance['lignes'];
        $totaux = $balance['totaux'];
        $row = ['TOTAL', null, null];

        if ($colonnes >= 4) {
            $row[] = $this->euros((int) collect($lignes)->sum('solde_ouverture_debit_centimes'));
            $row[] = $this->euros((int) collect($lignes)->sum('solde_ouverture_credit_centimes'));
        }

        if ($colonnes === 6) {
            $row[] = $this->euros((int) $totaux['mouvement_debit_centimes']);
            $row[] = $this->euros((int) $totaux['mouvement_credit_centimes']);
        }

        $row[] = $this->euros((int) $totaux['solde_fin_debit_centimes']);
        $row[] = $this->euros((int) $totaux['solde_fin_credit_centimes']);

        return $row;
    }

    private function euros(int $centimes): float
    {
        return $centimes / 100;
    }

    private function periodeLabel(string $dateDebut, string $dateFin): string
    {
        return 'Du '
            .CarbonImmutable::parse($dateDebut)->format('d/m/Y')
            .' au '
            .CarbonImmutable::parse($dateFin)->format('d/m/Y');
    }

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
