<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\TypeCategorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\Operation;
use App\Models\Recette;
use App\Models\SousCategorie;
use App\Models\Tiers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service d'import CSV pour les dépenses et recettes.
 *
 * Phase 1 : validation exhaustive sans écriture en base.
 * Phase 2 : insertion via DepenseService/RecetteService sans transaction globale
 *            (incompatible avec le SELECT FOR UPDATE de NumeroPieceService).
 *            En cas d'échec partiel, les transactions déjà committées sont conservées.
 */
final class CsvImportService
{
    private const EXPECTED_HEADERS = [
        'date', 'reference', 'sous_categorie', 'montant_ligne',
        'mode_paiement', 'compte', 'libelle', 'tiers', 'operation',
    ];

    /** @return string[] */
    private static function modesValides(): array
    {
        return array_column(ModePaiement::cases(), 'value');
    }

    public function import(UploadedFile $file, string $type): CsvImportResult
    {
        // --- Phase 1 : Lecture et validation ---

        $content = $this->readUtf8($file);
        if ($content === null) {
            return new CsvImportResult(false, errors: [[
                'line'    => 0,
                'message' => 'Le fichier doit être encodé en UTF-8. Enregistrez votre fichier CSV en UTF-8 depuis Excel ou LibreOffice.',
            ]]);
        }

        $rows = $this->parseCsv($content);

        if (empty($rows)) {
            return new CsvImportResult(false, errors: [['line' => 0, 'message' => 'Le fichier est vide.']]);
        }

        // Valider l'en-tête
        $headerError = $this->validateHeader($rows[0]);
        if ($headerError !== null) {
            return new CsvImportResult(false, errors: [['line' => 1, 'message' => $headerError]]);
        }

        array_shift($rows); // Retirer la ligne d'en-tête

        // Charger les lookups DB une seule fois (case-insensitive via lowercase key)
        $typeEnum  = TypeCategorie::from($type);
        $flagField = $type === 'depense' ? 'pour_depenses' : 'pour_recettes';
        $typeLabel = $type === 'depense' ? 'dépenses' : 'recettes';

        $sousCategories = SousCategorie::whereHas('categorie', fn ($q) => $q->where('type', $typeEnum))
            ->get()
            ->keyBy(fn ($sc) => Str::lower(trim($sc->nom)));

        $comptes = CompteBancaire::where('actif_recettes_depenses', true)
            ->get()
            ->keyBy(fn ($c) => Str::lower(trim($c->nom)));

        // Map tiers : displayName (lowercase) → liste de Tiers (pour détecter les homonymes)
        $tiersMap = [];
        foreach (Tiers::all() as $tiers) {
            $key              = Str::lower(trim($tiers->displayName()));
            $tiersMap[$key][] = $tiers;
        }

        $operations = Operation::all()->keyBy(fn ($op) => Str::lower(trim($op->nom)));

        // Parser et grouper les lignes par date+reference
        $errors     = [];
        $groups     = []; // groupKey => ['data' => [...], 'lignes' => [...], 'firstLine' => int]
        $groupOrder = []; // ordre d'apparition des groupes

        foreach ($rows as $idx => $row) {
            $csvLine = $idx + 2; // +1 pour l'en-tête, +1 pour l'indexation 1-based

            // Validation des champs par ligne
            $rowErrors = $this->validateRow($row, $csvLine, $sousCategories, $tiersMap, $operations, $flagField, $typeLabel);
            $errors    = array_merge($errors, $rowErrors);

            if (!empty($rowErrors)) {
                continue;
            }

            $date      = trim($row[0]);
            $reference = trim($row[1]);
            $groupKey  = $date . '|' . $reference;

            $scNom        = Str::lower(trim($row[2]));
            $sc           = $sousCategories[$scNom];
            $montant      = trim($row[3]);
            $operationNom = Str::lower(trim($row[8] ?? ''));
            $operationId  = $operationNom !== '' && isset($operations[$operationNom])
                ? $operations[$operationNom]->id
                : null;

            if (!isset($groups[$groupKey])) {
                // Première ligne de ce groupe : mode_paiement et compte sont obligatoires
                $mode      = trim($row[4] ?? '');
                $compteNom = Str::lower(trim($row[5] ?? ''));

                if ($mode === '') {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne mode_paiement : obligatoire sur la première ligne d\'une transaction.'];
                    continue;
                }

                if (!in_array($mode, self::modesValides(), true)) {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne mode_paiement : valeur "' . $mode . '" invalide. Valeurs acceptées : ' . implode(', ', self::modesValides()) . '.'];
                    continue;
                }

                if ($compteNom === '') {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne compte : obligatoire sur la première ligne d\'une transaction.'];
                    continue;
                }

                if (!isset($comptes[$compteNom])) {
                    $errors[] = ['line' => $csvLine, 'message' => 'Colonne compte : "' . trim($row[5]) . '" inconnu ou inactif (actif_recettes_depenses = false).'];
                    continue;
                }

                // Résoudre le tiers (déjà validé dans validateRow)
                $tiersCsvNom = Str::lower(trim($row[7] ?? ''));
                $tiersId     = null;
                if ($tiersCsvNom !== '') {
                    $tiersId = $tiersMap[$tiersCsvNom][0]->id;
                }

                $groups[$groupKey] = [
                    'data' => [
                        'date'          => $date,
                        'reference'     => $reference,
                        'libelle'       => trim($row[6] ?? '') !== '' ? trim($row[6]) : null,
                        'mode_paiement' => $mode,
                        'compte_id'     => $comptes[$compteNom]->id,
                        'tiers_id'      => $tiersId,
                        'montant_total' => 0.0,
                    ],
                    'lignes'    => [],
                    'firstLine' => $csvLine,
                ];
                $groupOrder[] = $groupKey;
            }

            $groups[$groupKey]['lignes'][] = [
                'sous_categorie_id' => $sc->id,
                'montant'           => (float) $montant,
                'operation_id'      => $operationId,
            ];
            $groups[$groupKey]['data']['montant_total'] =
                round($groups[$groupKey]['data']['montant_total'] + (float) $montant, 2);
        }

        // Vérifier les doublons en base (hors soft-deleted, sans filtre exercice)
        $modelClass = $type === 'depense' ? Depense::class : Recette::class;
        foreach ($groups as $key => $group) {
            [$date, $reference] = explode('|', $key, 2);
            $exists = $modelClass::withoutTrashed()
                ->whereDate('date', $date)
                ->where('reference', $reference)
                ->exists();
            if ($exists) {
                $errors[] = [
                    'line'    => $group['firstLine'],
                    'message' => "Doublon : la transaction du {$date} avec la référence \"{$reference}\" existe déjà en base.",
                ];
            }
        }

        if (!empty($errors)) {
            return new CsvImportResult(false, errors: $errors);
        }

        // --- Phase 2 : Insertion ---
        // Chaque appel à DepenseService/RecetteService gère sa propre transaction DB.
        // Pas de transaction englobante (incompatible avec SELECT FOR UPDATE de NumeroPieceService).

        $service             = $type === 'depense' ? app(DepenseService::class) : app(RecetteService::class);
        $transactionsCreated = 0;
        $lignesCreated       = 0;

        foreach ($groupOrder as $key) {
            $group = $groups[$key];
            try {
                $service->create($group['data'], $group['lignes']);
                $transactionsCreated++;
                $lignesCreated += count($group['lignes']);
            } catch (\Exception $e) {
                $alreadyInserted = $transactionsCreated > 0
                    ? "{$transactionsCreated} transaction(s) déjà insérée(s) avant l'erreur (ne les re-soumettez pas). "
                    : '';
                return new CsvImportResult(false, errors: [[
                    'line'    => 0,
                    'message' => $alreadyInserted . 'Erreur lors de l\'insertion : ' . $e->getMessage(),
                ]]);
            }
        }

        return new CsvImportResult(
            success:             true,
            transactionsCreated: $transactionsCreated,
            lignesCreated:       $lignesCreated,
        );
    }

    private function readUtf8(UploadedFile $file): ?string
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            return null;
        }

        // Supprimer le BOM UTF-8 si présent
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        return $content;
    }

    /** @return list<list<string>> */
    private function parseCsv(string $content): array
    {
        $rows  = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue; // Ignorer les lignes vides silencieusement
            }
            $rows[] = str_getcsv($line, ';');
        }

        return $rows;
    }

    private function validateHeader(array $row): ?string
    {
        $normalized = array_map(fn ($h) => Str::lower(trim($h)), $row);
        $missing    = array_diff(self::EXPECTED_HEADERS, $normalized);
        if (!empty($missing)) {
            return 'En-tête invalide. Colonnes manquantes : ' . implode(', ', $missing) . '.';
        }

        return null;
    }

    /**
     * Valide les champs d'une ligne (hors mode_paiement et compte, validés lors du groupement).
     *
     * @param  array<string, list<Tiers>>  $tiersMap
     * @return list<array{line: int, message: string}>
     */
    private function validateRow(
        array $row,
        int $csvLine,
        Collection $sousCategories,
        array $tiersMap,
        Collection $operations,
        string $flagField,
        string $typeLabel,
    ): array {
        $errors = [];

        // date (col 0) — format YYYY-MM-DD
        $date   = trim($row[0] ?? '');
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if ($date === '' || $parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne date : valeur \"{$date}\" invalide (format attendu : YYYY-MM-DD)."];
        }

        // reference (col 1)
        $reference = trim($row[1] ?? '');
        if ($reference === '') {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne reference : valeur vide (champ obligatoire).'];
        } elseif (mb_strlen($reference) > 100) {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne reference : valeur trop longue (max 100 caractères).'];
        }

        // sous_categorie (col 2)
        $scNom = Str::lower(trim($row[2] ?? ''));
        if ($scNom === '') {
            $errors[] = ['line' => $csvLine, 'message' => 'Colonne sous_categorie : valeur vide (champ obligatoire).'];
        } elseif (!isset($sousCategories[$scNom])) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne sous_categorie : \"{$row[2]}\" inconnue ou de mauvais type."];
        }

        // montant_ligne (col 3)
        $montant = trim($row[3] ?? '');
        if (!is_numeric($montant) || (float) $montant <= 0) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne montant_ligne : valeur \"{$montant}\" invalide (doit être un nombre > 0)."];
        }

        // tiers (col 7) — optionnel
        $tiersNom = Str::lower(trim($row[7] ?? ''));
        if ($tiersNom !== '') {
            $candidates = $tiersMap[$tiersNom] ?? [];
            if (empty($candidates)) {
                $errors[] = ['line' => $csvLine, 'message' => "Colonne tiers : \"{$row[7]}\" inconnu."];
            } elseif (count($candidates) > 1) {
                $nb       = count($candidates);
                $errors[] = ['line' => $csvLine, 'message' => "Colonne tiers : \"{$row[7]}\" — homonyme détecté ({$nb} tiers trouvés). Résolvez l'ambiguïté en base avant l'import."];
            } elseif (!$candidates[0]->{$flagField}) {
                $errors[] = ['line' => $csvLine, 'message' => "Le tiers \"{$row[7]}\" existe mais n'est pas autorisé pour les {$typeLabel}."];
            }
        }

        // operation (col 8) — optionnel
        $opNom = Str::lower(trim($row[8] ?? ''));
        if ($opNom !== '' && !isset($operations[$opNom])) {
            $errors[] = ['line' => $csvLine, 'message' => "Colonne operation : \"{$row[8]}\" inconnue."];
        }

        return $errors;
    }
}
