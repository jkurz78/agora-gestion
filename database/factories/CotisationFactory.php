<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModePaiement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\SousCategorie;
use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cotisation>
 */
class CotisationFactory extends Factory
{
    protected $model = Cotisation::class;

    public function definition(): array
    {
        return [
            'tiers_id' => Tiers::factory()->membre(),
            'sous_categorie_id' => SousCategorie::factory()->pourCotisations(),
            'exercice' => (int) date('Y'),
            'montant' => fake()->randomFloat(2, 10, 200),
            'date_paiement' => fake()->dateTimeBetween('-1 year', 'now'),
            'mode_paiement' => fake()->randomElement(ModePaiement::cases()),
            'compte_id' => CompteBancaire::factory(),
            'pointe' => fake()->boolean(30),
        ];
    }
}
