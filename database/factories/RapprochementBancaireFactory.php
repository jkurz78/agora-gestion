<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutRapprochement;
use App\Enums\TypeRapprochement;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RapprochementBancaire>
 */
class RapprochementBancaireFactory extends Factory
{
    protected $model = RapprochementBancaire::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'compte_id' => CompteBancaire::factory(),
            'date_fin' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'solde_ouverture' => fake()->randomFloat(2, 0, 10000),
            'solde_fin' => fake()->randomFloat(2, 0, 10000),
            'statut' => StatutRapprochement::EnCours,
            'type' => TypeRapprochement::Bancaire,
            'saisi_par' => User::factory(),
            'verrouille_at' => null,
        ];
    }
}
