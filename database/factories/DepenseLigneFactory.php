<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepenseLigne>
 */
class DepenseLigneFactory extends Factory
{
    protected $model = DepenseLigne::class;

    public function definition(): array
    {
        return [
            'depense_id' => Depense::factory(),
            'sous_categorie_id' => SousCategorie::factory(),
            'operation_id' => null,
            'seance' => null,
            'montant' => fake()->randomFloat(2, 10, 1000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
