<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Don;
use App\Models\Donateur;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Don>
 */
class DonFactory extends Factory
{
    protected $model = Don::class;

    public function definition(): array
    {
        return [
            'donateur_id' => Donateur::factory(),
            'date' => fake()->dateTimeBetween('-1 year', 'now'),
            'montant' => fake()->randomFloat(2, 10, 5000),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'objet' => fake()->optional()->sentence(3),
            'operation_id' => null,
            'seance' => null,
            'compte_id' => CompteBancaire::factory(),
            'pointe' => fake()->boolean(20),
            'recu_emis' => fake()->boolean(30),
            'saisi_par' => User::factory(),
        ];
    }
}
