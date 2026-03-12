<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Categorie;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SousCategorie>
 */
class SousCategorieFactory extends Factory
{
    protected $model = SousCategorie::class;

    public function definition(): array
    {
        return [
            'categorie_id' => Categorie::factory(),
            'nom' => fake()->words(2, true),
            'code_cerfa' => fake()->optional(0.3)->numerify('####'),
        ];
    }
}
