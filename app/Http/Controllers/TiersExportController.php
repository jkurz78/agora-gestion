<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tiers;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TiersExportController extends Controller
{
    /** @var list<string> */
    private const HEADERS = [
        'nom',
        'prenom',
        'entreprise',
        'email',
        'telephone',
        'adresse_ligne1',
        'code_postal',
        'ville',
        'pays',
        'pour_depenses',
        'pour_recettes',
    ];

    public function __invoke(Request $request): Response
    {
        $request->validate([
            'format' => ['required', 'in:csv,xlsx'],
            'filtre' => ['nullable', 'in:depenses,recettes'],
            'helloasso' => ['nullable', 'in:0,1'],
        ]);

        $query = Tiers::query()->orderBy('nom');

        if ($request->filtre === 'depenses') {
            $query->where('pour_depenses', true);
        } elseif ($request->filtre === 'recettes') {
            $query->where('pour_recettes', true);
        }

        if ($request->helloasso === '1') {
            $query->where('est_helloasso', true);
        }

        $tiers = $query->get();

        $rows = $tiers->map(fn (Tiers $t): array => [
            $t->getRawOriginal('nom') ?? '',
            $t->prenom ?? '',
            $t->entreprise ?? '',
            $t->email ?? '',
            $t->telephone ?? '',
            $t->adresse_ligne1 ?? '',
            $t->code_postal ?? '',
            $t->ville ?? '',
            $t->pays ?? '',
            $t->pour_depenses ? '1' : '0',
            $t->pour_recettes ? '1' : '0',
        ])->all();

        $filename = 'tiers-'.now()->format('Y-m-d').'.'.$request->format;

        if ($request->format === 'xlsx') {
            return $this->downloadXlsx($rows, $filename);
        }

        return $this->downloadCsv($rows, $filename);
    }

    private function downloadXlsx(array $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Header row (bold)
        foreach (self::HEADERS as $colIndex => $header) {
            $cell = $sheet->getCell([$colIndex + 1, 1]);
            $cell->setValue($header);
            $cell->getStyle()->getFont()->setBold(true);
        }

        // Data rows
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->getCell([$colIndex + 1, $rowIndex + 2])->setValue($value);
            }
        }

        // Auto-size columns
        foreach (range(1, count(self::HEADERS)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return new StreamedResponse(function () use ($writer): void {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function downloadCsv(array $rows, string $filename): StreamedResponse
    {
        return new StreamedResponse(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
            fputcsv($output, self::HEADERS, ';');

            foreach ($rows as $row) {
                fputcsv($output, $row, ';');
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
