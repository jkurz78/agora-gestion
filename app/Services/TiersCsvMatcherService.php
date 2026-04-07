<?php

declare(strict_types=1);

namespace App\Services;

use App\Livewire\TiersMergeModal;
use App\Models\Tiers;
use Illuminate\Support\Str;

final class TiersCsvMatcherService
{
    /**
     * Match parsed rows against existing tiers in the database.
     *
     * @param  array  $rows  — array of associative arrays from TiersCsvParseResult->rows
     * @return array — array of matched rows, each with added keys:
     *               'status' => 'new' | 'enrichment' | 'conflict'
     *               'matched_tiers_id' => ?int (the matched tiers ID, null if new)
     *               'matched_candidates' => array (list of Tiers IDs if homonymes)
     *               'conflict_fields' => array (list of field names with divergent values)
     *               'warnings' => array (e.g. ["⚠ même email que DUPONT Jean (#12)"])
     *               'decision_log' => string (pre-filled with matching decision, will be completed later)
     */
    public function match(array $rows): array
    {
        $allTiers = Tiers::all();

        // Build lookup maps
        /** @var array<string, list<Tiers>> */
        $byNamePrenom = [];
        /** @var array<string, list<Tiers>> */
        $byEntreprise = [];
        /** @var array<string, list<Tiers>> */
        $byEmail = [];

        foreach ($allTiers as $tiers) {
            // nom+prenom key (use raw attribute to avoid accessor uppercasing)
            $rawNom = $tiers->getRawOriginal('nom');
            $key = Str::lower((string) $rawNom).'|'.Str::lower((string) $tiers->prenom);
            $byNamePrenom[$key] ??= [];
            $byNamePrenom[$key][] = $tiers;

            // entreprise key
            $entreprise = (string) $tiers->entreprise;
            if ($entreprise !== '') {
                $entKey = Str::lower($entreprise);
                $byEntreprise[$entKey] ??= [];
                $byEntreprise[$entKey][] = $tiers;
            }

            // email key
            $email = (string) $tiers->email;
            if ($email !== '') {
                $emailKey = Str::lower($email);
                $byEmail[$emailKey] ??= [];
                $byEmail[$emailKey][] = $tiers;
            }
        }

        $results = [];

        foreach ($rows as $row) {
            $matched = $this->findMatches($row, $byNamePrenom, $byEntreprise);
            $warnings = $this->checkEmailWarnings($row, $byEmail, $matched);

            $results[] = $this->buildResult($row, $matched, $warnings);
        }

        return $results;
    }

    /**
     * Find matching tiers for a row based on name/entreprise.
     *
     * @return list<Tiers>
     */
    private function findMatches(array $row, array $byNamePrenom, array $byEntreprise): array
    {
        $type = $row['type'] ?? 'particulier';

        if ($type === 'entreprise') {
            $entreprise = trim((string) ($row['entreprise'] ?? ''));
            if ($entreprise === '') {
                return [];
            }

            return $byEntreprise[Str::lower($entreprise)] ?? [];
        }

        // particulier: look up by nom|prenom
        $nom = trim((string) ($row['nom'] ?? ''));
        $prenom = trim((string) ($row['prenom'] ?? ''));
        $key = Str::lower($nom).'|'.Str::lower($prenom);

        return $byNamePrenom[$key] ?? [];
    }

    /**
     * Check if the row's email matches a tiers that is NOT in the matched set.
     *
     * @param  list<Tiers>  $matched
     * @return list<string>
     */
    private function checkEmailWarnings(array $row, array $byEmail, array $matched): array
    {
        $email = trim((string) ($row['email'] ?? ''));
        if ($email === '') {
            return [];
        }

        $emailKey = Str::lower($email);
        $emailTiers = $byEmail[$emailKey] ?? [];

        if (empty($emailTiers)) {
            return [];
        }

        $matchedIds = array_map(fn (Tiers $t): int => $t->id, $matched);
        $warnings = [];

        foreach ($emailTiers as $tiers) {
            if (! in_array($tiers->id, $matchedIds, true)) {
                $warnings[] = "⚠ même email que {$tiers->displayName()} (#{$tiers->id})";
            }
        }

        return $warnings;
    }

    /**
     * Build the result array for a row given its matches and warnings.
     *
     * @param  list<Tiers>  $matched
     * @param  list<string>  $warnings
     */
    private function buildResult(array $row, array $matched, array $warnings): array
    {
        $base = array_merge($row, [
            'matched_tiers_id' => null,
            'matched_candidates' => [],
            'conflict_fields' => [],
            'warnings' => $warnings,
            'decision_log' => '',
        ]);

        // No match → new
        if (count($matched) === 0) {
            $base['status'] = 'new';
            $base['decision_log'] = 'Création automatique';

            return $base;
        }

        // Multiple matches (homonymes) → try to narrow down by email
        if (count($matched) > 1) {
            $rowEmail = Str::lower(trim((string) ($row['email'] ?? '')));

            if ($rowEmail !== '') {
                $emailMatches = array_filter($matched, fn (Tiers $t): bool => Str::lower((string) $t->email) === $rowEmail);

                if (count($emailMatches) === 1) {
                    // Email uniquely identifies one candidate → use it
                    $matched = array_values($emailMatches);
                    // Fall through to single-match logic below
                }
            }
        }

        // Still multiple matches → conflict
        if (count($matched) > 1) {
            $base['status'] = 'conflict';
            $base['matched_candidates'] = array_map(fn (Tiers $t): int => $t->id, $matched);
            $labels = Tiers::disambiguate(collect($matched));
            $base['candidate_labels'] = [];
            foreach ($matched as $t) {
                $base['candidate_labels'][$t->id] = $labels[$t->id] ?? $t->displayName();
            }
            $base['decision_log'] = '';

            return $base;
        }

        // Exactly 1 match → compare fields for conflicts
        $tiers = $matched[0];
        $base['matched_tiers_id'] = $tiers->id;

        $conflictFields = [];
        $enrichedFields = [];

        foreach (TiersMergeModal::MERGE_FIELDS as $field) {
            $rowValue = trim((string) ($row[$field] ?? ''));
            $tiersValue = $this->getTiersFieldValue($tiers, $field);

            if ($rowValue === '') {
                // Row value is empty → no conflict, keep existing
                continue;
            }

            if ($tiersValue === '') {
                // Tiers value is empty → enrichment (will fill in)
                $enrichedFields[] = $field;

                continue;
            }

            // Both non-empty → compare case-insensitively
            if (Str::lower($rowValue) !== Str::lower($tiersValue)) {
                $conflictFields[] = $field;
            }
        }

        if (! empty($conflictFields)) {
            $base['status'] = 'conflict';
            $base['conflict_fields'] = $conflictFields;
            $base['decision_log'] = '';

            return $base;
        }

        if (empty($enrichedFields)) {
            $base['status'] = 'identical';
            $base['decision_log'] = 'Identique — aucune modification';

            return $base;
        }

        $base['status'] = 'enrichment';
        $base['decision_log'] = 'Enrichissement auto ('.implode(', ', $enrichedFields).')';

        return $base;
    }

    /**
     * Get a tiers field value as a trimmed string, bypassing accessors for 'nom'.
     */
    private function getTiersFieldValue(Tiers $tiers, string $field): string
    {
        // Use raw attribute for 'nom' to avoid the uppercase accessor
        if ($field === 'nom') {
            return trim((string) ($tiers->getRawOriginal('nom') ?? ''));
        }

        return trim((string) ($tiers->$field ?? ''));
    }
}
