<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RapprochementBancaire>
 * @todo S1-Task22: remove the hardcoded association_id once TenantModel auto-fills from TenantContext.
 */
class RapprochementBancaireFactory extends Factory
{
    protected $model = RapprochementBancaire::class;

    public function definition(): array
    {
        return [
            'association_id' => 1,
            'compte_id' => CompteBancaire::factory(),
            'date_fin' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'solde_ouverture' => fake()->randomFloat(2, 0, 10000),
            'solde_fin' => fake()->randomFloat(2, 0, 10000),
            'statut' => StatutRapprochement::EnCours,
            'saisi_par' => User::factory(),
            'verrouille_at' => null,
        ];
    }
}
