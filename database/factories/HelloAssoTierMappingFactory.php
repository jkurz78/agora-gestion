<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\HelloAssoTierMapping;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelloAssoTierMapping>
 */
final class HelloAssoTierMappingFactory extends Factory
{
    protected $model = HelloAssoTierMapping::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? Association::factory(),
            'helloasso_form_slug' => 'cotisation-'.$this->faker->numberBetween(2024, 2026),
            'helloasso_tier_id' => $this->faker->unique()->numberBetween(10000, 99999),
            'helloasso_tier_label' => $this->faker->randomElement(['Adulte', 'Étudiant', 'Bienfaiteur', 'Famille']),
            'target_type' => FormuleAdhesion::class,
            'target_id' => FormuleAdhesion::factory(),
        ];
    }
}
