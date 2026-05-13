<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EncadrementPrevision;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EncadrementPrevision>
 */
final class EncadrementPrevisionFactory extends Factory
{
    protected $model = EncadrementPrevision::class;

    public function definition(): array
    {
        return [
            'operation_id' => Operation::factory(),
            'tiers_id' => Tiers::factory(),
            'sous_categorie_id' => SousCategorie::factory(),
            'seance_id' => Seance::factory(),
            'montant_prevu' => fake()->randomFloat(2, 50, 500),
        ];
    }
}
