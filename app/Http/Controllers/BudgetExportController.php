<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BudgetExportService;
use App\Services\ExerciceService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BudgetExportController extends Controller
{
    public function __invoke(Request $request, BudgetExportService $service, ExerciceService $exerciceService): Response
    {
        $request->validate([
            'format' => ['required', 'in:csv,xlsx'],
            'exercice' => ['required', 'integer'],
            'source' => ['required', 'in:zero,courant,budget'],
            'source_exercice' => ['nullable', 'integer'],
        ]);

        $exerciceCible = (int) $request->exercice;

        // L'exercice de référence est choisi, non plus déduit du courant :
        // l'AG votant en octobre, « le réalisé courant » ne ferait que deux mois.
        $exerciceSource = $request->filled('source_exercice')
            ? (int) $request->source_exercice
            : $exerciceService->current();

        if (! in_array($exerciceSource, $exerciceService->availableYears(), true)) {
            $exerciceSource = $exerciceService->current();
        }

        $source = match ($request->source) {
            'courant' => 'realise',
            'budget' => 'budget',
            default => 'zero',
        };

        $rows = $service->rows($exerciceCible, $source, $exerciceSource);
        $filename = 'budget-'.$exerciceService->label($exerciceCible).'.'.$request->format;

        if ($request->format === 'xlsx') {
            return $this->downloadXlsx($rows, $filename, $service->enTetes($exerciceSource));
        }

        $csv = $service->toCsv($rows);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows
     * @param  list<string>  $entetes
     */
    private function downloadXlsx(array $rows, string $filename, array $entetes): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(
            array_merge([$entetes], $rows)
        );

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
