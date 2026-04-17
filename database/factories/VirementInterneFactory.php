<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompteBancaire;
use App\Models\User;
use App\Models\VirementInterne;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VirementInterne>
 */
class VirementInterneFactory extends Factory
{
    protected $model = VirementInterne::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'montant' => fake()->randomFloat(2, 10, 5000),
            'compte_source_id' => CompteBancaire::factory(),
            'compte_destination_id' => CompteBancaire::factory(),
            'reference' => fake()->optional()->numerify('VIR-####'),
            'notes' => fake()->optional()->sentence(),
            'saisi_par' => User::factory(),
        ];
    }
}
