<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Adhesion>
 */
final class AdhesionFactory extends Factory
{
    protected $model = Adhesion::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? Association::factory(),
            'tiers_id' => Tiers::factory(),
            'exercice' => 2025,
            'transaction_id' => null,
            'gratuite' => true,
            'motif_gratuite' => $this->faker->sentence(3),
        ];
    }

    public function payee(): static
    {
        return $this->state(fn () => [
            'gratuite' => false,
            'motif_gratuite' => null,
            'transaction_id' => Transaction::factory(),
        ]);
    }
}
