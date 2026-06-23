<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireCampaignFactory extends Factory
{
    protected $model = QuestionnaireCampaign::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'operation_id' => Operation::factory(),
            'template_id' => null,
            'titre_affiche' => fake()->sentence(4),
            'intro' => fake()->optional()->paragraph(),
            'remerciement' => fake()->optional()->sentence(),
            'statut' => 'brouillon',
            'ouverte_at' => null,
            'cloturee_at' => null,
        ];
    }
}
