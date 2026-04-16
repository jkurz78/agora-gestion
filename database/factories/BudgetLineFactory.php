<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\SousCategorie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 *
 * @todo S1-Task39: remove the hardcoded association_id once TenantModel auto-fills from TenantContext.
 */
class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    public function definition(): array
    {
        return [
            'association_id' => 1,
            'sous_categorie_id' => SousCategorie::factory(),
            'exercice' => (int) date('Y'),
            'montant_prevu' => fake()->randomFloat(2, 100, 10000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
