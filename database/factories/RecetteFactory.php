<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Recette;
use App\Models\RecetteLigne;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recette>
 */
class RecetteFactory extends Factory
{
    protected $model = Recette::class;

    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'libelle' => fake()->sentence(4),
            'montant_total' => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'reference' => fake()->numerify('REF-####'),
            'compte_id' => CompteBancaire::factory(),
            'pointe' => fake()->boolean(20),
            'notes' => fake()->optional()->sentence(),
            'saisi_par' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Recette $recette) {
            $ligneCount = fake()->numberBetween(1, 3);
            $montants = $this->splitAmount((float) $recette->montant_total, $ligneCount);

            foreach ($montants as $montant) {
                RecetteLigne::factory()->create([
                    'recette_id' => $recette->id,
                    'montant' => $montant,
                ]);
            }
        });
    }

    /**
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
