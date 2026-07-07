<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Models\Participant;
use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireSubmission;
use Illuminate\Support\Collection;

final class QuestionnaireResultatService
{
    /** @return array{nb_invitations:int, nb_soumissions:int, taux:float, questions:array<int,array<string,mixed>>} */
    public function pourCampagne(QuestionnaireCampaign $campagne): array
    {
        return $this->pourCampagnes(collect([$campagne]));
    }

    /**
     * Résultats consolidés multi-campagnes (même modèle).
     *
     * @param  Collection<int, QuestionnaireCampaign>  $campagnes
     * @return array{nb_invitations:int, nb_soumissions:int, taux:float, questions:array<int,array<string,mixed>>}
     */
    public function pourCampagnes(Collection $campagnes): array
    {
        $campagneIds = $campagnes->pluck('id');

        $operationIds = $campagnes->pluck('operation_id')->unique()->filter();
        $nbParticipants = Participant::whereIn('operation_id', $operationIds)->count();

        $soumissions = QuestionnaireSubmission::whereIn('campaign_id', $campagneIds)
            ->where('statut', StatutSubmission::Soumise->value)
            ->with('answers')
            ->get();

        $nbSoumissions = $soumissions->count();
        $answersParQuestion = $soumissions->flatMap->answers->groupBy('campaign_question_id');

        $premiere = $campagnes->first();
        $questions = $premiere->questions()->get()
            ->filter(fn ($q) => $q->type->estReponse())
            ->map(function ($q) use ($answersParQuestion, $campagnes): array {
                $allAnswers = collect();
                foreach ($campagnes as $c) {
                    $mappedQ = $c->questions()->where('libelle', $q->libelle)->where('type', $q->type->value)->first();
                    if ($mappedQ !== null) {
                        $allAnswers = $allAnswers->merge($answersParQuestion->get($mappedQ->id, collect()));
                    }
                }

                return [
                    'libelle' => $q->libelle,
                    'type' => $q->type,
                    ...$this->agreger($q->type, $allAnswers, $q),
                ];
            })->values()->all();

        return [
            'nb_invitations' => $nbParticipants,
            'nb_soumissions' => $nbSoumissions,
            'taux' => $nbParticipants > 0 ? round($nbSoumissions / $nbParticipants * 100, 1) : 0.0,
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
            TypeQuestion::Satisfaction, TypeQuestion::Ressenti, TypeQuestion::SelectionNumerique => [
                'moyenne' => $answers->isNotEmpty()
                    ? round((float) $answers->avg('value_integer'), 1)
                    : null,
                'distribution' => $answers->countBy('value_integer')->all(),
                'n' => $answers->count(),
                ...(($question->config['commentaire'] ?? false)
                    ? ['verbatims' => $answers->pluck('value_text')->filter()->values()->all()]
                    : []),
            ],
            TypeQuestion::SatisfactionTexteLong => [
                'moyenne' => $answers->isNotEmpty()
                    ? round((float) $answers->avg('value_integer'), 1)
                    : null,
                'distribution' => $answers->countBy('value_integer')->all(),
                'n' => $answers->count(),
                'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
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
            TypeQuestion::ChoixMultiple => [
                'repartition' => collect($question->options())->map(function (array $opt) use ($answers): array {
                    $count = $answers->filter(function ($a) use ($opt): bool {
                        $selected = json_decode($a->value_option ?? '[]', true);

                        return is_array($selected) && in_array($opt['valeur'], $selected, true);
                    })->count();

                    return ['libelle' => $opt['libelle'], 'count' => $count];
                })->all(),
                'n' => $answers->count(),
            ],
            TypeQuestion::Nombre => [
                'moyenne' => $answers->isNotEmpty()
                    ? round((float) $answers->avg(fn ($a) => (float) $a->value_text), 2)
                    : null,
                'min' => $answers->isNotEmpty()
                    ? (float) $answers->min(fn ($a) => (float) $a->value_text)
                    : null,
                'max' => $answers->isNotEmpty()
                    ? (float) $answers->max(fn ($a) => (float) $a->value_text)
                    : null,
                'n' => $answers->count(),
            ],
            TypeQuestion::TexteCourt, TypeQuestion::TexteLong, TypeQuestion::Date, TypeQuestion::Email => [
                'verbatims' => $answers->pluck('value_text')->filter()->values()->all(),
                'n' => $answers->count(),
            ],
        };
    }
}
