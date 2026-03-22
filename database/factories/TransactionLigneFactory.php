<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionLigne> */
class TransactionLigneFactory extends Factory
{
    protected $model = TransactionLigne::class;

    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'sous_categorie_id' => SousCategorie::factory(),
            'montant' => fake()->randomFloat(2, 5, 500),
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
        ];
    }
}
