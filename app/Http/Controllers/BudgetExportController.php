<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\BudgetExport;
use App\Services\BudgetExportService;
use App\Services\ExerciceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

final class BudgetExportController extends Controller
{
    public function __invoke(Request $request, BudgetExportService $service, ExerciceService $exerciceService): Response
    {
        $validator = Validator::make($request->all(), [
            'format'   => ['required', 'in:csv,xlsx'],
            'exercice' => ['required', 'integer'],
            'source'   => ['required', 'in:zero,courant,n1'],
        ]);

        if ($validator->fails()) {
            abort(422, $validator->errors()->first());
        }

        $exerciceCible  = (int) $request->exercice;
        $exerciceCourant = $exerciceService->current();

        $sourceExercice = match($request->source) {
            'zero'   => null,
            'courant' => $exerciceCourant,
            'n1'     => $exerciceCourant - 1,
        };

        $rows     = $service->rows($exerciceCible, $sourceExercice);
        $filename = "budget-{$exerciceCible}.{$request->format}";

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
