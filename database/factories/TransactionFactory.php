<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(TypeTransaction::cases()),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'libelle' => fake()->sentence(4),
            'montant_total' => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'reference' => fake()->numerify('REF-####'),
            'compte_id' => CompteBancaire::factory(),
            'statut_reglement' => StatutReglement::EnAttente,
            'notes' => fake()->optional()->sentence(),
            'saisi_par' => User::factory(),
        ];
    }

    public function asDepense(): static
    {
        return $this->state(['type' => TypeTransaction::Depense]);
    }

    public function asRecette(): static
    {
        return $this->state(['type' => TypeTransaction::Recette]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Transaction $transaction) {
            $ligneCount = fake()->numberBetween(1, 3);
            $montants = $this->splitAmount((float) $transaction->montant_total, $ligneCount);
            foreach ($montants as $montant) {
                TransactionLigne::factory()->create([
                    'transaction_id' => $transaction->id,
                    'montant' => $montant,
                ]);
            }
        });
    }

    /** @return list<float> */
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
