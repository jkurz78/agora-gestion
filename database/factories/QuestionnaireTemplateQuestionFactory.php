<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireTemplateQuestionFactory extends Factory
{
    protected $model = QuestionnaireTemplateQuestion::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'template_id' => QuestionnaireTemplate::factory(),
            'libelle' => fake()->sentence(),
            'aide' => null,
            'type' => TypeQuestion::TexteCourt,
            'ordre' => 1,
            'obligatoire' => false,
            'grouper_avec_precedente' => false,
            'config' => null,
        ];
    }
}
