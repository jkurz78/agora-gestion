<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeTransaction;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provision>
 * @todo S1-Task22: remove the hardcoded association_id once TenantModel auto-fills from TenantContext.
 */
final class ProvisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'association_id' => 1,
            'exercice' => 2025,
            'type' => fake()->randomElement(TypeTransaction::cases()),
            'sous_categorie_id' => SousCategorie::factory(),
            'libelle' => 'Provision '.fake()->word(),
            'montant' => fake()->randomFloat(2, -5000, 5000),
            'tiers_id' => null,
            'operation_id' => null,
            'seance' => null,
            'date' => '2026-08-31',
            'notes' => null,
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
            'saisi_par' => User::factory(),
        ];
    }
}
