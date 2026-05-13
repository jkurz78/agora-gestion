<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Participant>
 */
final class ParticipantFactory extends Factory
{
    protected $model = Participant::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'tiers_id' => Tiers::factory(),
            'operation_id' => Operation::factory(),
            'date_inscription' => fake()->dateTimeBetween('-1 year', 'now'),
            'est_helloasso' => false,
        ];
    }
}
