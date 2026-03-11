<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\DepenseLigne;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Depense>
 */
class DepenseFactory extends Factory
{
    protected $model = Depense::class;

    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'libelle' => fake()->sentence(4),
            'montant_total' => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'beneficiaire' => fake()->optional()->company(),
            'reference' => fake()->optional()->numerify('REF-####'),
            'compte_id' => CompteBancaire::factory(),
            'pointe' => fake()->boolean(20),
            'notes' => fake()->optional()->sentence(),
            'saisi_par' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Depense $depense) {
            $ligneCount = fake()->numberBetween(1, 3);
            $montants = $this->splitAmount((float) $depense->montant_total, $ligneCount);

            foreach ($montants as $montant) {
                DepenseLigne::factory()->create([
                    'depense_id' => $depense->id,
                    'montant' => $montant,
                ]);
            }
        });
    }

    /**
     * Split a total amount into N parts that sum to the total.
     *
     * @return list<float>
     */
    private function splitAmount(float $total, int $parts): array
    {
        if ($parts === 1) {
            return [$total];
        }

        $amounts = [];
        $remaining = $total;

        for ($i = 0; $i < $parts - 1; $i++) {
            $amount = round($remaining / ($parts - $i) * fake()->randomFloat(2, 0.5, 1.5), 2);
            $amount = min($amount, $remaining - (($parts - $i - 1) * 0.01));
            $amount = max($amount, 0.01);
            $amounts[] = $amount;
            $remaining -= $amount;
        }

        $amounts[] = round($remaining, 2);

        return $amounts;
    }
}
