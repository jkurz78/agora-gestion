<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireTemplate;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireTemplateFactory extends Factory
{
    protected $model = QuestionnaireTemplate::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'titre_interne' => fake()->sentence(3),
            'titre_affiche' => 'Votre avis nous intéresse',
            'intro' => fake()->optional()->paragraph(),
            'remerciement' => 'Merci pour votre retour.',
            'actif' => true,
        ];
    }
}
