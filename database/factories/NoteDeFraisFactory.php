<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NoteDeFrais>
 */
final class NoteDeFraisFactory extends Factory
{
    protected $model = NoteDeFrais::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'tiers_id' => Tiers::factory(),
            'date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'libelle' => fake()->optional(0.7)->sentence(4),
            'statut' => StatutNoteDeFrais::Brouillon->value,
            'motif_rejet' => null,
            'transaction_id' => null,
            'submitted_at' => null,
            'validee_at' => null,
        ];
    }

    public function brouillon(): static
    {
        return $this->state(['statut' => StatutNoteDeFrais::Brouillon->value]);
    }

    public function soumise(): static
    {
        return $this->state([
            'statut' => StatutNoteDeFrais::Soumise->value,
            'submitted_at' => now(),
        ]);
    }

    public function validee(): static
    {
        return $this->state([
            'statut' => StatutNoteDeFrais::Validee->value,
            'validee_at' => now(),
        ]);
    }
}
