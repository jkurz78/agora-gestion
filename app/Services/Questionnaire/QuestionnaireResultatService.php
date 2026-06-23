<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use Illuminate\Support\Collection;

final class QuestionnaireResultatService
{
    /** @return array{nb_invitations:int, nb_soumissions:int, taux:float, questions:array<int,array<string,mixed>>} */
    public function pourCampagne(QuestionnaireCampaign $campagne): array
    {
        $nbInvitations = $campagne->invitations()->count();

        $soumissions = $campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with('answers')
            ->get();

        $nbSoumissions = $soumissions->count();
        $answersParQuestion = $soumissions->flatMap->answers->groupBy('campaign_question_id');

        $questions = $campagne->questions()->get()->map(function ($q) use ($answersParQuestion): array {
            $answers = $answersParQuestion->get($q->id, collect());

            return [
                'libelle' => $q->libelle,
                'type' => $q->type,
                ...$this->agreger($q->type, $answers, $q),
            ];
        })->all();

        return [
            'nb_invitations' => $nbInvitations,
            'nb_soumissions' => $nbSoumissions,
            'taux' => $nbInvitations > 0 ? round($nbSoumissions / $nbInvitations * 100, 1) : 0.0,
            'questions' => $questions,
        ];
    }

    /**
     * @param  Collection<int, QuestionnaireAnswer>  $answers
     * @return array<string, mixed>
     */
    private function agreger(TypeQuestion $type, Collection $answers, QuestionnaireCampaignQuestion $question): array
    {
        return match ($type) {
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti => [
                'moyenne' => $answers->isNotEmpty()
                    ? round((float) $answers->avg('value_integer'), 1)
                    : null,
                'distribution' => $answers->countBy('value_integer')->all(),
                'n' => $answers->count(),
            ],
            TypeQuestion::CaseACocher => [
                'oui' => $answers->where('value_boolean', true)->count(),
                'non' => $answers->where('value_boolean', false)->count(),
                'n' => $answers->count(),
            ],
            TypeQuestion::ChoixUnique => [
                'repartition' => $answers->groupBy('value_option')->map(fn ($g, $val) => [
                    'libelle' => $question->libelleOption((string) $val) ?? $val,
                    'count' => $g->count(),
                ])->values()->all(),
                'n' => $answers->count(),
            ],
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong => [
                'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
                'n' => $answers->count(),
            ],
        };
    }
}
