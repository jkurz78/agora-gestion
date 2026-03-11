<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompteBancaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompteBancaire>
 */
class CompteBancaireFactory extends Factory
{
    protected $model = CompteBancaire::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->company() . ' - ' . fake()->randomElement(['Courant', 'Livret A', 'Épargne']),
            'iban' => fake()->iban('FR'),
            'solde_initial' => fake()->randomFloat(2, 0, 10000),
            'date_solde_initial' => fake()->date(),
        ];
    }
}
