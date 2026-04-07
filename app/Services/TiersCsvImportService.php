<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tiers;
use Illuminate\Support\Facades\DB;

final class TiersCsvImportService
{
    public function __construct(
        private readonly TiersService $tiersService,
    ) {}

    /**
     * Import all resolved rows in a single DB transaction.
     *
     * @param  array  $resolvedRows  Each row is an associative array with keys:
     *                               Original data: nom, prenom, entreprise, email, telephone, adresse_ligne1, code_postal, ville, pays, pour_depenses, pour_recettes, type
     *                               Resolution data: status ('new'|'enrichment'|'conflict_resolved_merge'|'conflict_resolved_new'), matched_tiers_id, merge_data, decision_log, line, warnings
     * @param  string  $filename  Original filename for the report
     */
    public function import(array $resolvedRows, string $filename): TiersCsvImportReport
    {
        return DB::transaction(function () use ($resolvedRows): TiersCsvImportReport {
            $created = 0;
            $enriched = 0;
            $resolvedMerge = 0;
            $resolvedNew = 0;
            $lines = [];

            foreach ($resolvedRows as $row) {
                $status = $row['status'];

                if ($status === 'identical') {
                    // Nothing to do — tiers already up to date
                    $lines[] = [
                        'line' => $row['line'],
                        'entreprise' => $row['entreprise'] ?? null,
                        'nom' => $row['nom'] ?? null,
                        'prenom' => $row['prenom'] ?? null,
                        'decision' => $row['decision_log'] ?? 'Identique',
                    ];

                    continue;
                }

                match ($status) {
                    'new' => $this->handleNew($row, $created),
                    'enrichment' => $this->handleEnrichment($row, $enriched),
                    'conflict_resolved_merge' => $this->handleConflictResolvedMerge($row, $resolvedMerge),
                    'conflict_resolved_new' => $this->handleConflictResolvedNew($row, $resolvedNew),
                };

                $lines[] = [
                    'line' => $row['line'],
                    'entreprise' => $row['entreprise'] ?? null,
                    'nom' => $row['nom'] ?? null,
                    'prenom' => $row['prenom'] ?? null,
                    'decision' => $row['decision_log'] ?? $status,
                ];
            }

            return new TiersCsvImportReport(
                created: $created,
                enriched: $enriched,
                resolvedMerge: $resolvedMerge,
                resolvedNew: $resolvedNew,
                lines: $lines,
            );
        });
    }

    private function handleNew(array $row, int &$counter): void
    {
        $this->tiersService->create($this->buildCreateData($row));
        $counter++;
    }

    private function handleEnrichment(array $row, int &$counter): void
    {
        $tiers = Tiers::findOrFail($row['matched_tiers_id']);
        $data = $this->buildEnrichmentData($tiers, $row);

        if ($data !== []) {
            $this->tiersService->update($tiers, $data);
        }

        $counter++;
    }

    private function handleConflictResolvedMerge(array $row, int &$counter): void
    {
        $tiers = Tiers::findOrFail($row['matched_tiers_id']);
        $this->tiersService->update($tiers, $row['merge_data']);
        $counter++;
    }

    private function handleConflictResolvedNew(array $row, int &$counter): void
    {
        $this->tiersService->create($this->buildCreateData($row));
        $counter++;
    }

    private function buildCreateData(array $row): array
    {
        return [
            'type' => $row['type'],
            'nom' => $row['nom'] ?? null,
            'prenom' => $row['prenom'] ?? null,
            'entreprise' => $row['entreprise'] ?? null,
            'email' => $row['email'] ?? null,
            'telephone' => $row['telephone'] ?? null,
            'adresse_ligne1' => $row['adresse_ligne1'] ?? null,
            'code_postal' => $row['code_postal'] ?? null,
            'ville' => $row['ville'] ?? null,
            'pays' => $row['pays'] ?? 'France',
            'pour_depenses' => (bool) ($row['pour_depenses'] ?? true),
            'pour_recettes' => (bool) ($row['pour_recettes'] ?? true),
        ];
    }

    private function buildEnrichmentData(Tiers $tiers, array $row): array
    {
        $data = [];

        foreach (['nom', 'prenom', 'entreprise', 'email', 'telephone', 'adresse_ligne1', 'code_postal', 'ville', 'pays'] as $field) {
            $rowValue = $row[$field] ?? null;
            // Read raw attribute to bypass the uppercase accessor on nom
            $tiersValue = $tiers->getRawOriginal($field);

            if (! empty($rowValue) && empty($tiersValue)) {
                $data[$field] = $rowValue;
            }
        }

        // Boolean OR logic: import sets to true only if currently false
        foreach (['pour_depenses', 'pour_recettes'] as $field) {
            if (! $tiers->{$field} && ! empty($row[$field])) {
                $data[$field] = true;
            }
        }

        return $data;
    }
}
