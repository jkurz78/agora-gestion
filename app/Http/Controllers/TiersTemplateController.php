<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TiersTemplateController extends Controller
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

    /** @var list<string> */
    private const EXAMPLE_ROW = [
        'Dupont',
        'Jean',
        '',
        'jean.dupont@email.fr',
        '06 12 34 56 78',
        '1 rue de la Paix',
        '75001',
        'Paris',
        'France',
        '1',
        '1',
    ];

    public function csv(): StreamedResponse
    {
        $filename = 'modele-tiers.csv';

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, self::HEADERS, ';');
            fputcsv($output, self::EXAMPLE_ROW, ';');

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function xlsx(): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Header row (bold)
        foreach (self::HEADERS as $colIndex => $header) {
            $cell = $sheet->getCell([$colIndex + 1, 1]);
            $cell->setValue($header);
            $cell->getStyle()->getFont()->setBold(true);
        }

        // Example row
        foreach (self::EXAMPLE_ROW as $colIndex => $value) {
            $sheet->getCell([$colIndex + 1, 2])->setValue($value);
        }

        // Auto-size columns
        foreach (range(1, count(self::HEADERS)) as $colIndex) {
            $columnLetter = Coordinate::stringFromColumnIndex($colIndex);
            $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'tiers_template_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return response()->download(
            $tempFile,
            'modele-tiers.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }
}
