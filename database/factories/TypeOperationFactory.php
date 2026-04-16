<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SousCategorie;
use App\Models\TypeOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeOperation>
 * @todo S1-Task39: remove the hardcoded association_id once TenantModel auto-fills from TenantContext.
 */
class TypeOperationFactory extends Factory
{
    protected $model = TypeOperation::class;

    public function definition(): array
    {
        return [
            'association_id' => 1,
            'nom' => fake()->unique()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'sous_categorie_id' => SousCategorie::factory(),
            'nombre_seances' => fake()->optional()->numberBetween(5, 30),
            'formulaire_parcours_therapeutique' => false,
            'formulaire_actif' => false,
            'reserve_adherents' => false,
            'actif' => true,
            'logo_path' => null,
        ];
    }

    public function confidentiel(): static
    {
        return $this->state([
            'formulaire_parcours_therapeutique' => true,
            'formulaire_actif' => true,
        ]);
    }

    public function reserveAdherents(): static
    {
        return $this->state(['reserve_adherents' => true]);
    }

    public function inactif(): static
    {
        return $this->state(['actif' => false]);
    }
}
