<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Famille;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Famille>
 */
class FamilleFactory extends Factory
{
    protected $model = Famille::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            // 2 chiffres uniques commençant par 6 (dépense) ou 7 (recette) —
            // évite les collisions sur l'unique (association_id, code). 20
            // combinaisons possibles (6/7 x 0-9), suffisant pour des tests
            // unitaires ; fake()->unique() lève une exception au-delà.
            'code' => (string) fake()->unique()->numberBetween(60, 79),
            'nom' => fake()->words(2, true),
        ];
    }
}
