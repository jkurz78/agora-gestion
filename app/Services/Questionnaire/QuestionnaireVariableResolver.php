<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Support\CurrentAssociation;

final class QuestionnaireVariableResolver
{
    /**
     * @return array<string, string>
     */
    public function pour(QuestionnaireInvitation $invitation, bool $avecLien = false): array
    {
        $participant = $invitation->participant;
        $tiers = $participant?->tiers;
        $operation = $invitation->campaign->operation;

        $vars = [
            '{prenom}' => (string) ($tiers?->prenom ?? ''),
            '{nom}' => (string) ($tiers?->nom ?? ''),
            '{civilite}' => (string) ($tiers?->civilite?->value ?? ''),
            '{politesse}' => (string) ($tiers?->civilite?->label() ?? ''),
            '{operation}' => (string) ($operation?->nom ?? ''),
            '{type_operation}' => (string) ($operation?->typeOperation?->nom ?? ''),
            '{association}' => (string) (CurrentAssociation::tryGet()?->nom ?? ''),
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? '',
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? '',
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? ''),
        ];

        if ($avecLien) {
            $vars['{lien_questionnaire}'] = $invitation->lienReponse();
        }

        return $vars;
    }

    /**
     * @return array<string, string>
     */
    public function exemple(?QuestionnaireCampaign $campagne = null): array
    {
        $operation = $campagne?->operation;

        return [
            '{prenom}' => 'Jean',
            '{nom}' => 'Dupont',
            '{civilite}' => 'M.',
            '{politesse}' => 'Monsieur',
            '{operation}' => $operation?->nom ?? 'Mon opération',
            '{type_operation}' => $operation?->typeOperation?->nom ?? 'Type d\'opération',
            '{association}' => CurrentAssociation::tryGet()?->nom ?? 'Mon association',
            '{date_debut}' => $operation?->date_debut?->format('d/m/Y') ?? now()->format('d/m/Y'),
            '{date_fin}' => $operation?->date_fin?->format('d/m/Y') ?? now()->addMonth()->format('d/m/Y'),
            '{nb_seances}' => (string) ($operation?->nombre_seances ?? '6'),
        ];
    }

    /**
     * Remplace les {variables} ; les valeurs sont échappées (anti-injection HTML).
     *
     * @param  array<string, string>  $vars
     */
    public function remplacer(string $html, array $vars): string
    {
        $echappees = array_map(fn (string $v): string => e($v), $vars);

        return strtr($html, $echappees);
    }
}
