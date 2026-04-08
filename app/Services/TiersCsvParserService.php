<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class TiersCsvParserService
{
    public const EXPECTED_HEADERS = [
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

    public const REQUIRED_HEADERS = ['nom', 'prenom', 'entreprise', 'email'];

    public function parse(UploadedFile $file): TiersCsvParseResult
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xls') {
            return new TiersCsvParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le format .xls n\'est pas supporté. Veuillez convertir le fichier en .xlsx ou .csv.'],
            ]);
        }

        $rawRows = $this->parseFile($file, $ext);

        if ($rawRows === null) {
            return new TiersCsvParseResult(false, errors: [
                ['line' => 0, 'message' => 'Fichier illisible ou format non supporté.'],
            ]);
        }

        if (empty($rawRows)) {
            return new TiersCsvParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le fichier est vide.'],
            ]);
        }

        // Validate and map header
        $headerResult = $this->validateHeader($rawRows[0]);

        if ($headerResult === null) {
            return new TiersCsvParseResult(false, errors: [
                ['line' => 1, 'message' => 'En-tête du fichier non reconnu. Aucune colonne attendue trouvée (nom, entreprise, email...).'],
            ]);
        }

        /** @var array<int, string> $columnMap position => column name */
        $columnMap = $headerResult;

        $dataRows = array_slice($rawRows, 1);

        if (empty($dataRows)) {
            return new TiersCsvParseResult(false, errors: [
                ['line' => 0, 'message' => 'Le fichier ne contient aucune ligne de données.'],
            ]);
        }

        $errors = [];
        $rows = [];
        /** @var array<string, int> $seenKeys key => line number (first occurrence) */
        $seenKeys = [];
        $hasDepensesColumn = in_array('pour_depenses', $columnMap, true);
        $hasRecettesColumn = in_array('pour_recettes', $columnMap, true);

        foreach ($dataRows as $idx => $rawRow) {
            $lineNum = $idx + 2; // header is line 1

            // Map raw row to associative array
            $row = $this->mapRow($rawRow, $columnMap);

            // Validate: at least nom or entreprise
            $nom = trim($row['nom'] ?? '');
            $entreprise = trim($row['entreprise'] ?? '');

            if ($nom === '' && $entreprise === '') {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : nom ou entreprise requis."];

                continue;
            }

            // Validate email format
            $email = trim($row['email'] ?? '');
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : email invalide ({$email})."];

                continue;
            }

            // Type deduction
            $type = $entreprise !== '' ? 'entreprise' : 'particulier';
            $row['type'] = $type;

            // Flags: pour_depenses / pour_recettes
            $row['pour_depenses'] = $this->resolveFlag($row['pour_depenses'] ?? '', $hasDepensesColumn);
            $row['pour_recettes'] = $this->resolveFlag($row['pour_recettes'] ?? '', $hasRecettesColumn);

            // Default pays
            $pays = trim($row['pays'] ?? '');
            $row['pays'] = $pays !== '' ? $pays : 'France';

            // Trim all string values
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row[$key] = trim($value);
                }
            }

            // Duplicate detection: same identity = same name + same email (or both emails empty)
            // Two rows with same nom+prenom but different emails are legitimate homonymes
            $identityKey = $type === 'entreprise'
                ? Str::lower($entreprise)
                : Str::lower($nom.'|'.trim($row['prenom'] ?? ''));
            $dupKey = $identityKey.'|'.Str::lower($email);

            if (isset($seenKeys[$dupKey])) {
                $firstLine = $seenKeys[$dupKey];
                $label = $type === 'entreprise'
                    ? $entreprise
                    : trim(($row['prenom'] ?? '').' '.$nom);
                $errors[] = [
                    'line' => $lineNum,
                    'message' => "Lignes {$firstLine} et {$lineNum} : doublon ({$label}).",
                ];

                continue;
            }

            $seenKeys[$dupKey] = $lineNum;
            $rows[] = $row;
        }

        if (! empty($errors)) {
            return new TiersCsvParseResult(false, errors: $errors);
        }

        return new TiersCsvParseResult(true, rows: $rows);
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

        // Normalize line endings and parse
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

    /**
     * @return list<list<string>>|null
     */
    private function parseXlsx(UploadedFile $file): ?array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            return array_map(
                fn (array $row): array => array_map(fn ($v): string => (string) ($v ?? ''), $row),
                $rows
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function detectSeparator(string $line): string
    {
        $semicolonCount = substr_count($line, ';');
        $commaCount = substr_count($line, ',');

        return $semicolonCount >= $commaCount ? ';' : ',';
    }

    /**
     * Validate header and return column map (position => column name), or null if invalid.
     *
     * @return array<int, string>|null
     */
    private function validateHeader(array $row): ?array
    {
        $columnMap = [];

        foreach ($row as $position => $header) {
            $normalized = Str::lower(trim((string) $header));
            if (in_array($normalized, self::EXPECTED_HEADERS, true)) {
                $columnMap[$position] = $normalized;
            }
        }

        // At least 'nom' or 'entreprise' must be present
        $mappedColumns = array_values($columnMap);

        if (! in_array('nom', $mappedColumns, true) && ! in_array('entreprise', $mappedColumns, true)) {
            return null;
        }

        return $columnMap;
    }

    /**
     * Map a raw row array to an associative array using the column map.
     *
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
     * Resolve a boolean flag value.
     * If the column is not present in the file, default to true.
     * If the column is present but value is empty, default to true.
     * Accept '1'/'0'/'oui'/'non'/'true'/'false' (case-insensitive).
     */
    private function resolveFlag(string $value, bool $columnExists): bool
    {
        if (! $columnExists) {
            return true;
        }

        $value = Str::lower(trim($value));

        if ($value === '') {
            return true;
        }

        return in_array($value, ['1', 'oui', 'true'], true);
    }
}
