<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireSubmission;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireAnswerFactory extends Factory
{
    protected $model = QuestionnaireAnswer::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'submission_id' => QuestionnaireSubmission::factory(),
            'campaign_question_id' => QuestionnaireCampaignQuestion::factory(),
            'value_text' => fake()->sentence(),
        ];
    }
}
