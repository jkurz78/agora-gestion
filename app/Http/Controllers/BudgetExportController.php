<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\BudgetExport;
use App\Services\BudgetExportService;
use App\Services\ExerciceService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

final class BudgetExportController extends Controller
{
    public function __invoke(Request $request, BudgetExportService $service, ExerciceService $exerciceService): Response
    {
        $request->validate([
            'format'   => ['required', 'in:csv,xlsx'],
            'exercice' => ['required', 'integer'],
            'source'   => ['required', 'in:zero,courant,budget'],
        ]);

        $exerciceCible   = (int) $request->exercice;
        $exerciceCourant = $exerciceService->current();

        $source = match($request->source) {
            'courant' => 'realise',
            'budget'  => 'budget',
            default   => 'zero',
        };

        $rows     = $service->rows($exerciceCible, $source, $exerciceCourant);
        $filename = 'budget-'.$exerciceService->label($exerciceCible).'.'.$request->format;

        if ($request->format === 'xlsx') {
            return Excel::download(new BudgetExport($rows), $filename);
        }

        $csv = $service->toCsv($rows);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
