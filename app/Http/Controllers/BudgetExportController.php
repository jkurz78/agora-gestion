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
        ]);

        $exerciceCible = (int) $request->exercice;
        $exerciceCourant = $exerciceService->current();

        $source = match ($request->source) {
            'courant' => 'realise',
            'budget' => 'budget',
            default => 'zero',
        };

        $rows = $service->rows($exerciceCible, $source, $exerciceCourant);
        $filename = 'budget-'.$exerciceService->label($exerciceCible).'.'.$request->format;

        if ($request->format === 'xlsx') {
            return $this->downloadXlsx($rows, $filename);
        }

        $csv = $service->toCsv($rows);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $rows */
    private function downloadXlsx(array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(
            array_merge([['exercice', 'categorie', 'sous_categorie', 'montant_prevu']], $rows)
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
