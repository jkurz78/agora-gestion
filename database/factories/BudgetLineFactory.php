<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    public function definition(): array
    {
        return [
            'sous_categorie_id' => SousCategorie::factory(),
            'exercice' => (int) date('Y'),
            'montant_prevu' => fake()->randomFloat(2, 100, 10000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
