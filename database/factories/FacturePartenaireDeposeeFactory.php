<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturePartenaireDeposee>
 */
final class FacturePartenaireDeposeeFactory extends Factory
{
    protected $model = FacturePartenaireDeposee::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'tiers_id' => Tiers::factory()->pourDepenses(),
            'date_facture' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'numero_facture' => strtoupper(fake()->bothify('FACT-####-??')),
            'pdf_path' => 'factures/'.fake()->uuid().'.pdf',
            'pdf_taille' => fake()->numberBetween(10000, 5000000),
            'statut' => StatutFactureDeposee::Soumise->value,
            'motif_rejet' => null,
            'transaction_id' => null,
            'traitee_at' => null,
        ];
    }

    public function soumise(): static
    {
        return $this->state([
            'statut' => StatutFactureDeposee::Soumise->value,
            'traitee_at' => null,
        ]);
    }

    public function traitee(): static
    {
        return $this->state([
            'statut' => StatutFactureDeposee::Traitee->value,
            'traitee_at' => now(),
        ]);
    }

    public function rejetee(string $motif = 'Document illisible'): static
    {
        return $this->state([
            'statut' => StatutFactureDeposee::Rejetee->value,
            'motif_rejet' => $motif,
            'traitee_at' => now(),
        ]);
    }
}
