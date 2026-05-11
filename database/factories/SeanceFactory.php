<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operation;
use App\Models\Seance;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seance>
 */
final class SeanceFactory extends Factory
{
    protected $model = Seance::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'operation_id' => Operation::factory(),
            'numero' => $this->faker->unique()->numberBetween(1, 99999),
            'date' => fake()->dateTimeBetween('-1 year', '+1 year'),
            'titre' => null,
        ];
    }
}
