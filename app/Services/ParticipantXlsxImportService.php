<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Participant;
use App\Models\ParticipantDonneesMedicales;
use Illuminate\Support\Facades\DB;

final class ParticipantXlsxImportService
{
    /** Keys in a row that map to medical data. */
    private const MEDICAL_ROW_KEYS = [
        'date_naissance',
        'sexe',
        'poids_kg',
        'taille_cm',
        'nom_jeune_fille',
        'nationalite',
    ];

    public function __construct(private readonly TiersService $tiersService) {}

    /**
     * Import matched rows into the database.
     *
     * Precondition: no row must have status 'conflict'.
     * Rows with 'already_participant' are silently skipped.
     *
     * @param  array<int, array<string, mixed>>  $matchedRows
     */
    public function import(array $matchedRows, int $operationId, string $filename): ParticipantXlsxImportReport
    {
        return DB::transaction(function () use ($matchedRows, $operationId): ParticipantXlsxImportReport {
            $created = 0;
            $linked = 0;
            $skipped = 0;
            $lines = [];

            foreach ($matchedRows as $row) {
                $status = (string) $row['status'];
                $lineNum = (int) ($row['_line'] ?? 0);
                $nom = (string) ($row['nom'] ?? '');
                $prenom = (string) ($row['prenom'] ?? '');

                if ($status === 'already_participant') {
                    $skipped++;
                    $lines[] = [
                        'line' => $lineNum,
                        'nom' => $nom,
                        'prenom' => $prenom,
                        'decision' => 'Déjà participant (ignoré)',
                    ];

                    continue;
                }

                if ($status === 'conflict') {
                    // Should never be called with conflicts — defensive skip
                    continue;
                }

                $tiersId = $this->resolveTiersId($row, $status);
                $participant = $this->createParticipant($tiersId, $operationId, $row);

                $this->createDonneesMedicalesIfNeeded($participant->id, $row);

                if ($status === 'new') {
                    $created++;
                    $decision = 'Nouveau tiers créé + participant ajouté';
                } else {
                    $linked++;
                    $decision = "Tiers existant lié (#{$tiersId}) + participant ajouté";
                }

                $lines[] = [
                    'line' => $lineNum,
                    'nom' => $nom,
                    'prenom' => $prenom,
                    'decision' => $decision,
                ];
            }

            return new ParticipantXlsxImportReport(
                created: $created,
                linked: $linked,
                skipped: $skipped,
                lines: $lines,
            );
        });
    }

    /**
     * Create the Tiers if status is 'new', otherwise return the matched tiers ID.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveTiersId(array $row, string $status): int
    {
        if ($status === 'matched') {
            return (int) $row['matched_tiers_id'];
        }

        // status === 'new': create a new Tiers
        $tiers = $this->tiersService->create([
            'type' => 'particulier',
            'nom' => (string) ($row['nom'] ?? ''),
            'prenom' => (string) ($row['prenom'] ?? ''),
            'email' => (string) ($row['email'] ?? '') ?: null,
            'telephone' => (string) ($row['telephone'] ?? '') ?: null,
            'adresse_ligne1' => (string) ($row['adresse_ligne1'] ?? '') ?: null,
            'code_postal' => (string) ($row['code_postal'] ?? '') ?: null,
            'ville' => (string) ($row['ville'] ?? '') ?: null,
            'pays' => 'France',
            'pour_depenses' => true,
            'pour_recettes' => true,
        ]);

        return (int) $tiers->id;
    }

    /**
     * Create a Participant record.
     *
     * @param  array<string, mixed>  $row
     */
    private function createParticipant(int $tiersId, int $operationId, array $row): Participant
    {
        $dateInscription = (string) ($row['date_inscription'] ?? '');
        $notes = (string) ($row['notes'] ?? '');
        $nomJeuneFille = (string) ($row['nom_jeune_fille'] ?? '');
        $nationalite = (string) ($row['nationalite'] ?? '');

        return Participant::create([
            'tiers_id' => $tiersId,
            'operation_id' => $operationId,
            'date_inscription' => $dateInscription !== '' ? $dateInscription : null,
            'notes' => $notes !== '' ? $notes : null,
            'nom_jeune_fille' => $nomJeuneFille !== '' ? $nomJeuneFille : null,
            'nationalite' => $nationalite !== '' ? $nationalite : null,
        ]);
    }

    /**
     * Create ParticipantDonneesMedicales if any medical field is non-empty.
     *
     * @param  array<string, mixed>  $row
     */
    private function createDonneesMedicalesIfNeeded(int $participantId, array $row): void
    {
        $medicalData = $this->extractMedicalData($row);

        if (empty($medicalData)) {
            return;
        }

        ParticipantDonneesMedicales::create(array_merge(
            ['participant_id' => $participantId],
            $medicalData,
        ));
    }

    /**
     * Extract and map medical fields from a row.
     * Returns only fields that are non-empty.
     * Keys are remapped to match ParticipantDonneesMedicales fillable fields.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function extractMedicalData(array $row): array
    {
        $data = [];

        $dateNaissance = (string) ($row['date_naissance'] ?? '');
        if ($dateNaissance !== '') {
            $data['date_naissance'] = $dateNaissance;
        }

        $sexe = (string) ($row['sexe'] ?? '');
        if ($sexe !== '') {
            $data['sexe'] = $sexe;
        }

        // poids_kg → poids
        $poids = (string) ($row['poids_kg'] ?? '');
        if ($poids !== '') {
            $data['poids'] = $poids;
        }

        // taille_cm → taille
        $taille = (string) ($row['taille_cm'] ?? '');
        if ($taille !== '') {
            $data['taille'] = $taille;
        }

        return $data;
    }
}
