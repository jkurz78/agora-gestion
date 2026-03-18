<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\SousCategorie;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

final class BudgetImportService
{
    private const EXPECTED_HEADERS = ['exercice', 'sous_categorie', 'montant_prevu'];

    public function import(UploadedFile $file, int $exercice): BudgetImportResult
    {
        $rows = $this->parseFile($file);

        if ($rows === null) {
            return new BudgetImportResult(false, errors: [['line' => 0, 'message' => 'Fichier illisible ou format non supporté.']]);
        }

        if (empty($rows)) {
            return new BudgetImportResult(false, errors: [['line' => 0, 'message' => 'Le fichier est vide.']]);
        }

        // Valider l'en-tête
        $headerError = $this->validateHeader($rows[0]);
        if ($headerError !== null) {
            return new BudgetImportResult(false, errors: [['line' => 1, 'message' => $headerError]]);
        }

        $dataRows = array_slice($rows, 1);

        if (empty($dataRows)) {
            return new BudgetImportResult(false, errors: [['line' => 0, 'message' => 'Le fichier ne contient aucune ligne de données.']]);
        }

        // Charger toutes les sous-catégories indexées par nom (lowercase)
        // Détecte les homonymes : clé => [SousCategorie, ...]
        /** @var array<string, list<SousCategorie>> */
        $scByName = [];
        foreach (SousCategorie::all() as $sc) {
            $key = Str::lower(trim($sc->nom));
            $scByName[$key][] = $sc;
        }

        $errors           = [];
        $wrongExercices   = [];

        foreach ($dataRows as $idx => $row) {
            $lineNum       = $idx + 2;
            $exerciceCell  = trim((string) ($row[0] ?? ''));
            $scNom         = trim((string) ($row[1] ?? ''));
            $montantCell   = trim((string) ($row[2] ?? ''));

            // Exercice
            if ($exerciceCell !== (string) $exercice) {
                $wrongExercices[] = $exerciceCell;
            }

            // Sous-catégorie
            if ($scNom === '') {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : sous-catégorie vide (champ obligatoire)."];
            } elseif (!isset($scByName[Str::lower($scNom)])) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : sous-catégorie '{$scNom}' introuvable."];
            } elseif (count($scByName[Str::lower($scNom)]) > 1) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : nom '{$scNom}' ambigu (plusieurs sous-catégories portent ce nom)."];
            }

            // Montant : vide ou zéro sont acceptés (la ligne sera ignorée à l'import)
            // Négatif ou non-numérique sont des erreurs
            if ($montantCell !== '') {
                if (!is_numeric($montantCell)) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : montant_prevu '{$montantCell}' invalide (nombre >= 0 attendu ou cellule vide)."];
                } elseif ((float) $montantCell < 0) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : montant_prevu '{$montantCell}' invalide (nombre >= 0 attendu ou cellule vide)."];
                }
                // Note: (float) $montantCell === 0.0 is accepted (line will be skipped at insert)
            }
        }

        // Erreur exercice : rapport groupé
        if (!empty($wrongExercices)) {
            $unique = array_unique($wrongExercices);
            sort($unique);
            $list   = implode(', ', $unique);
            $errors = array_merge([['line' => 0, 'message' => "Le fichier contient les exercices {$list}, l'exercice ouvert est {$exercice}."]], $errors);
        }

        if (!empty($errors)) {
            return new BudgetImportResult(false, errors: $errors);
        }

        // Insertion dans une transaction DB
        $inserted = 0;

        DB::transaction(function () use ($dataRows, $exercice, $scByName, &$inserted) {
            BudgetLine::where('exercice', $exercice)->delete();

            foreach ($dataRows as $row) {
                $scNom       = trim((string) ($row[1] ?? ''));
                $montantCell = trim((string) ($row[2] ?? ''));

                // Ignorer montant vide ou zéro
                if ($montantCell === '' || $montantCell === '0' || $montantCell === '0.00') {
                    continue;
                }

                if (!is_numeric($montantCell) || (float) $montantCell <= 0) {
                    continue;
                }

                $sc = $scByName[Str::lower($scNom)][0];

                BudgetLine::create([
                    'sous_categorie_id' => $sc->id,
                    'exercice'          => $exercice,
                    'montant_prevu'     => (float) $montantCell,
                ]);

                $inserted++;
            }
        });

        return new BudgetImportResult(true, linesImported: $inserted);
    }

    /**
     * Retourne les lignes du fichier (tableau 2D de strings), ou null en cas d'erreur.
     *
     * @return list<list<string>>|null
     */
    private function parseFile(UploadedFile $file): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'xlsx') {
            return $this->parseXlsx($file);
        }

        return $this->parseCsv($file);
    }

    /** @return list<list<string>>|null */
    private function parseCsv(UploadedFile $file): ?array
    {
        $content = file_get_contents($file->getRealPath());

        if ($content === false) {
            return null;
        }

        // Supprimer BOM UTF-8
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $rows  = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map('strval', str_getcsv($line, ';'));
        }

        return $rows;
    }

    /** @return list<list<string>>|null */
    private function parseXlsx(UploadedFile $file): ?array
    {
        $import = new class implements ToArray {
            /** @var list<list<string>> */
            public array $data = [];

            public function array(array $array): void
            {
                $this->data = array_map(
                    fn (array $row): array => array_map(fn ($v): string => (string) ($v ?? ''), $row),
                    $array
                );
            }
        };

        try {
            Excel::import($import, $file);
        } catch (\Throwable) {
            return null;
        }

        return $import->data;
    }

    private function validateHeader(array $row): ?string
    {
        $normalized = array_map(fn ($h) => Str::lower(trim($h)), $row);
        $missing    = array_diff(self::EXPECTED_HEADERS, $normalized);

        if (!empty($missing)) {
            return 'En-tête invalide. Colonnes manquantes ou incorrectes : ' . implode(', ', $missing) . '.';
        }

        return null;
    }
}
