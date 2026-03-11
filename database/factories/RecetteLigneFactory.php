<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecetteLigne>
 */
class RecetteLigneFactory extends Factory
{
    protected $model = RecetteLigne::class;

    public function definition(): array
    {
        return [
            'recette_id' => Recette::factory(),
            'sous_categorie_id' => SousCategorie::factory(),
            'operation_id' => null,
            'seance' => null,
            'montant' => fake()->randomFloat(2, 10, 1000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
