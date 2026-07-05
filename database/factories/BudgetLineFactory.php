<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\SousCategorie;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
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
            'association_id' => TenantContext::currentId() ?? 1,
            'sous_categorie_id' => SousCategorie::factory(),
            'exercice' => app(ExerciceService::class)->current(),
            'montant_prevu' => fake()->randomFloat(2, 100, 10000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
