<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Compte;
use App\Services\Budget\BudgetGelService;
use App\Services\Compta\PlanComptableSelecteur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class BudgetImportService
{
    private const EXPECTED_HEADERS = ['exercice', 'famille', 'compte', 'montant_prevu'];

    public function import(UploadedFile $file, int $exercice): BudgetImportResult
    {
        if (app(BudgetGelService::class)->estValide($exercice)) {
            return new BudgetImportResult(false, errors: [[
                'line' => 0,
                'message' => 'Le budget de cet exercice est validé. Le déverrouiller avant d\'importer.',
            ]]);
        }

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

        // Charger les comptes éligibles au budget, indexés par intitulé
        // (lowercase). Détecte les homonymes : clé => [Compte, ...].
        //
        // Même liste blanche que BudgetTable::addLine() et
        // BudgetAffectationModal::enregistrer() : sans elle, un fichier
        // nommant un compte de classe 5 (bancaire) ou un compte désactivé
        // créait une enveloppe que l'écran Budget ne liste jamais (classes
        // 6-7 actives uniquement) — ligne invisible et non supprimable
        // depuis l'interface.
        $comptesAutorisesIds = PlanComptableSelecteur::comptesAutorisesPourTypes(['depense', 'recette']);

        /** @var array<string, list<Compte>> */
        $compteByName = [];
        // Comptes réels mais hors périmètre budgétaire (autre classe, ou
        // désactivés) : sert uniquement à distinguer, dans le message
        // d'erreur, "compte introuvable" (rien ne porte ce nom) de "compte
        // hors périmètre" (le nom existe, mais ce compte-là ne peut pas
        // porter de budget) — un seul exemple par nom suffit à ce diagnostic.
        /** @var array<string, Compte> */
        $compteHorsPerimetreByName = [];
        foreach (Compte::all() as $compte) {
            $key = Str::lower(trim($compte->intitule));

            if (in_array($compte->id, $comptesAutorisesIds, true)) {
                $compteByName[$key][] = $compte;
            } elseif (! isset($compteHorsPerimetreByName[$key])) {
                $compteHorsPerimetreByName[$key] = $compte;
            }
        }

        $errors = [];
        $wrongExercices = [];

        // Lignes visées par compte (nom normalisé => numéros de ligne). Sert à
        // détecter deux lignes visant le MÊME compte : depuis que l'unicité
        // (association_id, exercice, compte_id, operation_key) existe en base,
        // la seconde insertion lèverait une QueryException non rattrapée — une
        // 500 — au lieu d'un message de validation propre. Seuls les comptes
        // résolus sans ambiguïté comptent ici : un compte déjà en erreur
        // (introuvable/ambigu) n'a pas besoin d'un second diagnostic.
        $lignesParCompte = [];

        foreach ($dataRows as $idx => $row) {
            $lineNum = $idx + 2;
            $exerciceCell = trim((string) ($row[0] ?? ''));
            // col 1 = famille — ignorée à l'import (lecture seule)
            $compteNom = trim((string) ($row[2] ?? ''));
            $montantCell = trim((string) ($row[3] ?? ''));

            if ($compteNom !== '' && isset($compteByName[Str::lower($compteNom)]) && count($compteByName[Str::lower($compteNom)]) === 1) {
                $lignesParCompte[Str::lower($compteNom)][] = $lineNum;
            }

            // Exercice : accepte "2025" ou "2025-2026"
            $exerciceCellYear = str_contains($exerciceCell, '-')
                ? (int) explode('-', $exerciceCell)[0]
                : (int) $exerciceCell;

            if ($exerciceCellYear !== $exercice) {
                $wrongExercices[] = $exerciceCell;
            }

            // Compte
            if ($compteNom === '') {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : compte vide (champ obligatoire)."];
            } elseif (! isset($compteByName[Str::lower($compteNom)])) {
                if (isset($compteHorsPerimetreByName[Str::lower($compteNom)])) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : le compte '{$compteNom}' existe, mais seuls les comptes de charges et de produits actifs peuvent porter un budget."];
                } else {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : compte '{$compteNom}' introuvable."];
                }
            } elseif (count($compteByName[Str::lower($compteNom)]) > 1) {
                $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : nom '{$compteNom}' ambigu (plusieurs comptes portent ce nom)."];
            }

            // Montant : vide ou zéro sont acceptés (la ligne sera ignorée à l'import)
            // Négatif ou non-numérique sont des erreurs
            if ($montantCell !== '') {
                if (! is_numeric($montantCell)) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : montant_prevu '{$montantCell}' invalide (nombre >= 0 attendu ou cellule vide)."];
                } elseif ((float) $montantCell < 0) {
                    $errors[] = ['line' => $lineNum, 'message' => "Ligne {$lineNum} : montant_prevu '{$montantCell}' invalide (nombre >= 0 attendu ou cellule vide)."];
                }
                // Note: (float) $montantCell === 0.0 is accepted (line will be skipped at insert)
            }
        }

        // Erreur exercice : rapport groupé, dans la même notation des deux
        // côtés (libellé complet, ex. "2025-2026"), avec une invitation à
        // changer d'exercice — sauf si le fichier mélange plusieurs
        // exercices, auquel cas ce conseil serait faux : c'est le fichier
        // qui est incohérent, pas l'exercice affiché.
        if (! empty($wrongExercices)) {
            $labels = array_values(array_unique(array_map(
                fn (string $raw): string => $this->formatExerciceLabel($raw),
                $wrongExercices
            )));
            sort($labels);
            $exerciceLabel = app(ExerciceService::class)->label($exercice);

            if (count($labels) === 1) {
                $message = "Ce fichier porte l'exercice {$labels[0]}, alors que l'exercice affiché est {$exerciceLabel}. "
                    ."Basculez sur l'exercice {$labels[0]} avant d'importer.";
            } else {
                $list = implode(', ', $labels);
                $message = "Ce fichier mélange plusieurs exercices ({$list}), alors que l'exercice affiché est {$exerciceLabel}. "
                    ."Un fichier d'import ne doit porter qu'un seul exercice.";
            }

            $errors = array_merge([['line' => 0, 'message' => $message]], $errors);
        }

        // Erreur comptes en doublon : même motif de rapport groupé.
        $comptesEnDoublon = array_filter($lignesParCompte, fn (array $lignes): bool => count($lignes) > 1);
        if (! empty($comptesEnDoublon)) {
            $noms = array_map(
                fn (string $cle): string => $compteByName[$cle][0]->intitule,
                array_keys($comptesEnDoublon)
            );
            sort($noms);
            $list = implode(', ', $noms);
            $errors = array_merge([['line' => 0, 'message' => "Le fichier contient plusieurs lignes pour le(s) compte(s) : {$list}. Une seule ligne par compte est autorisée."]], $errors);
        }

        if (! empty($errors)) {
            return new BudgetImportResult(false, errors: $errors);
        }

        // Insertion dans une transaction DB
        $inserted = 0;

        DB::transaction(function () use ($dataRows, $exercice, $compteByName, &$inserted) {
            // Seules les enveloppes sont remplacées. La ventilation par opération
            // est construite en cours d'année, dans l'application : un budget
            // rectificatif chargé en février la détruirait sans un mot.
            BudgetLine::where('exercice', $exercice)->whereNull('operation_id')->delete();

            foreach ($dataRows as $row) {
                $compteNom = trim((string) ($row[2] ?? ''));
                $montantCell = trim((string) ($row[3] ?? ''));

                // Ignorer montant vide ou zéro
                if ($montantCell === '' || $montantCell === '0' || $montantCell === '0.00') {
                    continue;
                }

                if (! is_numeric($montantCell) || (float) $montantCell <= 0) {
                    continue;
                }

                $compte = $compteByName[Str::lower($compteNom)][0];

                BudgetLine::create([
                    'compte_id' => $compte->id,
                    'exercice' => $exercice,
                    'montant_prevu' => (float) $montantCell,
                ]);

                $inserted++;
            }
        });

        return new BudgetImportResult(true, linesImported: $inserted);
    }

    /**
     * Ce qu'un import remplacerait et ce qu'il conserverait.
     *
     * La règle « les enveloppes sont remplacées, la ventilation est conservée »
     * serait sinon implicite : l'utilisateur n'en découvrirait l'effet qu'après
     * coup.
     *
     * @return array{enveloppes: int, ventilations: int, montant_ventile: float, operations: int}
     */
    public function compteRendu(int $exercice): array
    {
        $ventilations = BudgetLine::forExercice($exercice)->ventilations()->get();

        return [
            'enveloppes' => BudgetLine::forExercice($exercice)->enveloppes()->count(),
            'ventilations' => $ventilations->count(),
            'montant_ventile' => round((float) $ventilations->sum('montant_prevu'), 2),
            'operations' => $ventilations->pluck('operation_id')->unique()->count(),
        ];
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

        if (! mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        $rows = [];
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

    /**
     * Met la valeur d'exercice d'une cellule du fichier dans la même
     * notation que celle affichée pour l'exercice ouvert (libellé complet,
     * ex. "2025-2026"), en acceptant l'année seule ("2025") comme le fait
     * le parseur. Une valeur non parsable (vide, "toto"...) est reprise
     * telle quelle plutôt que de produire un libellé absurde comme "0-1".
     */
    private function formatExerciceLabel(string $raw): string
    {
        $yearPart = trim(str_contains($raw, '-') ? explode('-', $raw)[0] : $raw);

        if (! preg_match('/^\d+$/', $yearPart)) {
            return $raw;
        }

        return app(ExerciceService::class)->label((int) $yearPart);
    }

    private function validateHeader(array $row): ?string
    {
        $normalized = array_map(fn ($h) => Str::lower(trim($h)), $row);
        $missing = array_diff(self::EXPECTED_HEADERS, $normalized);

        if (! empty($missing)) {
            return 'En-tête invalide. Colonnes manquantes ou incorrectes : '.implode(', ', $missing).'.';
        }

        return null;
    }
}
