<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categorie>
 */
class CategorieFactory extends Factory
{
    protected $model = Categorie::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->word(),
            'type' => fake()->randomElement(TypeCategorie::cases()),
        ];
    }

    public function depense(): static
    {
        return $this->state(fn () => ['type' => TypeCategorie::Depense]);
    }

    public function recette(): static
    {
        return $this->state(fn () => ['type' => TypeCategorie::Recette]);
    }
}
