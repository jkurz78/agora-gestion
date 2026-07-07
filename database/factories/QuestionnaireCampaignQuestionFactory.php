<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireCampaignQuestionFactory extends Factory
{
    protected $model = QuestionnaireCampaignQuestion::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'libelle' => fake()->sentence(5).' ?',
            'aide' => fake()->optional()->sentence(),
            'type' => TypeQuestion::TexteCourt,
            'ordre' => fake()->numberBetween(0, 10),
            'obligatoire' => false,
            'grouper_avec_precedente' => false,
            'config' => null,
        ];
    }
}
