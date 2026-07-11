<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransactionLigne>
 *
 * DC-10a — pas de ventilation par défaut : une ligne brute n'a ni sous_categorie_id
 * ni compte_id tant que le test ne le demande pas explicitement.
 */
class TransactionLigneFactory extends Factory
{
    protected $model = TransactionLigne::class;

    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'montant' => fake()->randomFloat(2, 5, 500),
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ];
    }
}
