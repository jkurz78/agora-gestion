<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutInvitation;
use App\Models\QuestionnaireCampaign;
use Illuminate\Support\Facades\DB;

final class QuestionnaireInvitationService
{
    public function __construct(private readonly QuestionnaireTokenService $tokens) {}

    /**
     * @param  array<int>  $participantIds
     * @return array<int, string> participant_id => token CLAIR (à utiliser pour les liens/QR, jamais stocké)
     */
    public function genererPour(QuestionnaireCampaign $campagne, array $participantIds): array
    {
        return DB::transaction(function () use ($campagne, $participantIds): array {
            $clairs = [];

            foreach ($participantIds as $pid) {
                $pid = (int) $pid;

                // Invariant : une invitation par (campagne, participant) — la contrainte unique
                // protège, mais on évite l'exception en sautant les existants.
                $existe = $campagne->invitations()->where('participant_id', $pid)->exists();
                if ($existe) {
                    continue;
                }

                $pair = $this->tokens->generer();
                $campagne->invitations()->create([
                    'participant_id' => $pid,
                    'token_hash' => $pair['hash'],
                    'token_chiffre' => $pair['clair'], // cast encrypted → chiffré à l'écriture
                    'code_court' => $this->tokens->codeCourt(),
                    'statut' => StatutInvitation::NonOuvert,
                ]);

                $clairs[$pid] = $pair['clair'];
            }

            return $clairs;
        });
    }
}
