<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Participant;
use App\Models\Tiers;
use Illuminate\Support\Str;

final class ParticipantXlsxMatcherService
{
    /**
     * Match each parsed row against existing Tiers and check participation status.
     *
     * Each returned row is enriched with:
     *   - status: 'new' | 'matched' | 'already_participant' | 'conflict'
     *   - matched_tiers_id: ?int
     *   - decision_log: string
     *   - warnings: list<string>
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function match(array $rows, int $operationId): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[] = $this->matchRow($row, $operationId);
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function matchRow(array $row, int $operationId): array
    {
        $warnings = [];
        $email = trim($row['email'] ?? '');
        $nom = trim($row['nom'] ?? '');
        $prenom = trim($row['prenom'] ?? '');

        // Step 1: match by email (most reliable)
        if ($email !== '') {
            $byEmail = Tiers::where('email', $email)->get();

            if ($byEmail->count() === 1) {
                $tiers = $byEmail->first();

                return $this->resolveMatched($row, $tiers, $operationId, $warnings);
            }

            if ($byEmail->count() > 1) {
                // Multiple tiers share the same email — unusual but possible
                $ids = $byEmail->pluck('id')->map(fn ($id): string => '#'.$id)->implode(', ');
                $warnings[] = "Plusieurs tiers partagent cet email ({$ids}).";
            }
            // count === 0 or ambiguous email → fall through to nom+prenom matching
        }

        // Step 2: match by nom+prenom (case-insensitive)
        if ($nom !== '' && $prenom !== '') {
            $byName = Tiers::whereRaw('LOWER(nom) = ?', [Str::lower($nom)])
                ->whereRaw('LOWER(prenom) = ?', [Str::lower($prenom)])
                ->get();

            if ($byName->count() === 0) {
                return $this->buildRow($row, 'new', null, 'Nouveau tiers — sera créé', $warnings);
            }

            if ($byName->count() === 1) {
                $tiers = $byName->first();

                return $this->resolveMatched($row, $tiers, $operationId, $warnings);
            }

            // 2+ results → conflict
            $ids = $byName->pluck('id')->map(fn ($id): string => '#'.$id)->implode(', ');

            return $this->buildRow(
                $row,
                'conflict',
                null,
                "Conflit : homonymes trouvés ({$ids}) — précisez l'email",
                $warnings,
            );
        }

        // No email and no complete nom+prenom → treat as new
        // (parser validation should have caught this, but be defensive)
        return $this->buildRow($row, 'new', null, 'Nouveau tiers — sera créé', $warnings);
    }

    /**
     * Resolve a matched Tiers: check if already a participant.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function resolveMatched(array $row, Tiers $tiers, int $operationId, array $warnings): array
    {
        $tiersId = (int) $tiers->id;
        $displayName = trim(($tiers->prenom ? $tiers->prenom.' ' : '').$tiers->getRawOriginal('nom'));

        $alreadyParticipant = Participant::where('tiers_id', $tiersId)
            ->where('operation_id', $operationId)
            ->exists();

        if ($alreadyParticipant) {
            return $this->buildRow(
                $row,
                'already_participant',
                $tiersId,
                'Déjà participant (ignoré)',
                $warnings,
            );
        }

        return $this->buildRow(
            $row,
            'matched',
            $tiersId,
            "Tiers existant : {$displayName} (#{$tiersId})",
            $warnings,
        );
    }

    /**
     * Build an enriched row.
     *
     * @param  array<string, string>  $row
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function buildRow(
        array $row,
        string $status,
        ?int $matchedTiersId,
        string $decisionLog,
        array $warnings,
    ): array {
        return array_merge($row, [
            'status' => $status,
            'matched_tiers_id' => $matchedTiersId,
            'decision_log' => $decisionLog,
            'warnings' => $warnings,
        ]);
    }
}
