<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final class ParticipantXlsxParserService
{
    /** Colonnes de base (toujours reconnues) */
    private const BASE_COLUMNS = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'adresse_ligne1',
        'code_postal',
        'ville',
        'date_inscription',
        'notes',
    ];

    /** Colonnes médicales optionnelles */
    private const MEDICAL_COLUMNS = [
        'date_naissance',
        'sexe',
        'poids_kg',
        'taille_cm',
        'nom_jeune_fille',
        'nationalite',
    ];

    private const ALL_COLUMNS = [...self::BASE_COLUMNS, ...self::MEDICAL_COLUMNS];

    public function parse(UploadedFile $file): ParticipantXlsxParseResult
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xls') {
            return new ParticipantXlsxParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le format .xls n\'est pas supporté. Veuillez convertir le fichier en .xlsx ou .csv.'],
            ]);
        }

        $rawRows = $this->parseFile($file, $ext);

        if ($rawRows === null) {
            return new ParticipantXlsxParseResult(false, errors: [
                ['line' => 0, 'message' => 'Fichier illisible ou format non supporté.'],
            ]);
        }

        if (empty($rawRows)) {
            return new ParticipantXlsxParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le fichier est vide.'],
            ]);
        }

        $columnMap = $this->buildColumnMap($rawRows[0]);

        if (empty($columnMap)) {
            return new ParticipantXlsxParseResult(false, errors: [
                ['line' => 1, 'message' => 'En-tête du fichier non reconnu. Aucune colonne attendue trouvée (nom, prenom, email...).'],
            ]);
        }

        $dataRows = array_slice($rawRows, 1);

        if (empty($dataRows)) {
            return new ParticipantXlsxParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le fichier ne contient aucune ligne de données.'],
            ]);
        }

        $errors = [];
        $rows = [];
        /** @var array<string, int> $seenKeys dupKey => first line number */
        $seenKeys = [];

        foreach ($dataRows as $idx => $rawRow) {
            $lineNum = $idx + 2; // header is line 1

            $row = $this->mapRow($rawRow, $columnMap);

            // Trim all string values first
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row[$key] = trim($value);
                }
            }

            $nom = $row['nom'] ?? '';
            $prenom = $row['prenom'] ?? '';
            $email = $row['email'] ?? '';

            // Validation : email requis OU (nom ET prenom tous deux présents)
            if ($email === '' && ($nom === '' || $prenom === '')) {
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Ligne {$lineNum} : email requis, ou nom et prénom tous deux présents.",
                ];

                continue;
            }

            // Validation format email
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Ligne {$lineNum} : email invalide ({$email}).",
                ];

                continue;
            }

            // Validation date_inscription
            if (isset($row['date_inscription']) && $row['date_inscription'] !== '') {
                $converted = $this->parseDate($row['date_inscription']);
                if ($converted === null) {
                    $errors[] = [
                        'line' => $lineNum,
                        'message' => "Ligne {$lineNum} : date_inscription invalide ({$row['date_inscription']}). Format attendu : dd/mm/yyyy ou yyyy-mm-dd.",
                    ];

                    continue;
                }
                $row['date_inscription'] = $converted;
            }

            // Validation date_naissance
            if (isset($row['date_naissance']) && $row['date_naissance'] !== '') {
                $converted = $this->parseDate($row['date_naissance']);
                if ($converted === null) {
                    $errors[] = [
                        'line' => $lineNum,
                        'message' => "Ligne {$lineNum} : date_naissance invalide ({$row['date_naissance']}). Format attendu : dd/mm/yyyy ou yyyy-mm-dd.",
                    ];

                    continue;
                }
                $row['date_naissance'] = $converted;
            }

            // Validation poids_kg
            if (isset($row['poids_kg']) && $row['poids_kg'] !== '' && ! is_numeric($row['poids_kg'])) {
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Ligne {$lineNum} : poids_kg doit être numérique ({$row['poids_kg']}).",
                ];

                continue;
            }

            // Validation taille_cm
            if (isset($row['taille_cm']) && $row['taille_cm'] !== '' && ! is_numeric($row['taille_cm'])) {
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Ligne {$lineNum} : taille_cm doit être numérique ({$row['taille_cm']}).",
                ];

                continue;
            }

            // Duplicate detection: same nom+prenom+email
            $dupKey = Str::lower($nom.'|'.$prenom.'|'.$email);
            if (isset($seenKeys[$dupKey])) {
                $firstLine = $seenKeys[$dupKey];
                $label = trim($prenom.' '.$nom);
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Lignes {$firstLine} et {$lineNum} : doublon ({$label}).",
                ];

                continue;
            }

            $seenKeys[$dupKey] = $lineNum;
            $row['_line'] = $lineNum;
            $rows[] = $row;
        }

        if (! empty($errors)) {
            return new ParticipantXlsxParseResult(false, errors: $errors);
        }

        return new ParticipantXlsxParseResult(true, rows: $rows);
    }

    /**
     * @return list<list<string>>|null
     */
    private function parseFile(UploadedFile $file, string $ext): ?array
    {
        if ($ext === 'xlsx') {
            return $this->parseXlsx($file);
        }

        return $this->parseCsv($file);
    }

    /**
     * @return list<list<string>>|null
     */
    private function parseXlsx(UploadedFile $file): ?array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();

            // formatData=false : on récupère les valeurs brutes.
            // Les cellules "Date" Excel arrivent comme des sériels numériques (ex. 46025.0)
            // au lieu de "12/30/2025" (format US que PhpSpreadsheet produirait avec formatData=true).
            // On les convertit explicitement en Y-m-d via excelToDateTimeObject().
            $rawRows = $sheet->toArray(null, true, false, false);

            return array_map(
                fn (array $row): array => array_map(fn ($v): string => (string) ($v ?? ''), $row),
                $rawRows
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<list<string>>|null
     */
    private function parseCsv(UploadedFile $file): ?array
    {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            return null;
        }

        // Remove UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        // Auto-detect encoding: if not valid UTF-8, try Windows-1252
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        // Auto-detect separator: count occurrences in first line
        $firstLine = strtok($content, "\n");
        $separator = $this->detectSeparator($firstLine !== false ? $firstLine : '');

        $rows = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map('strval', str_getcsv($line, $separator));
        }

        return $rows;
    }

    private function detectSeparator(string $line): string
    {
        $semicolonCount = substr_count($line, ';');
        $commaCount = substr_count($line, ',');

        return $semicolonCount >= $commaCount ? ';' : ',';
    }

    /**
     * Build a column map (position => column name) from the header row.
     * Unknown columns are silently ignored.
     *
     * @param  list<string>  $headerRow
     * @return array<int, string>
     */
    private function buildColumnMap(array $headerRow): array
    {
        $columnMap = [];

        foreach ($headerRow as $position => $header) {
            $normalized = Str::lower(trim((string) $header));
            if (in_array($normalized, self::ALL_COLUMNS, true)) {
                $columnMap[$position] = $normalized;
            }
        }

        return $columnMap;
    }

    /**
     * Map a raw row array to an associative array using the column map.
     *
     * @param  list<string>  $rawRow
     * @param  array<int, string>  $columnMap
     * @return array<string, string>
     */
    private function mapRow(array $rawRow, array $columnMap): array
    {
        $row = [];

        foreach ($columnMap as $position => $columnName) {
            $row[$columnName] = (string) ($rawRow[$position] ?? '');
        }

        return $row;
    }

    /**
     * Parse a date value in various formats.
     * Accepts:
     *   - Excel date serial (numeric string, ex. "46025" → 2025-12-30)
     *   - dd/mm/yyyy or d/mm/yyyy or dd/m/yyyy (French format)
     *   - yyyy-mm-dd (ISO format)
     * Returns Y-m-d string or null if invalid/unrecognized.
     */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Excel date serial : entier numérique dans la plage réaliste des dates (1900–2100).
        // Plage 20000–80000 couvre ~1954–2118 sans risque de collision avec des valeurs métier
        // (poids, taille, CP) qui restent bien en dessous de 20000.
        if (ctype_digit($value)) {
            $serial = (int) $value;
            if ($serial >= 20000 && $serial <= 80000) {
                try {
                    $dt = ExcelDate::excelToDateTimeObject($serial);

                    return $dt->format('Y-m-d');
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        // dd/mm/yyyy ou d/m/yyyy (format français, séparateur /)
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        // yyyy-mm-dd (ISO)
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $value, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        return null;
    }
}
