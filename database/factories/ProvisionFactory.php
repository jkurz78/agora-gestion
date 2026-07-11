<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Provision;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provision>
 */
final class ProvisionFactory extends Factory
{
    public function definition(): array
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'exercice' => $exercice,
            'type' => fake()->randomElement(TypeTransaction::cases()),
            'compte_id' => Compte::factory(),
            'libelle' => 'Provision '.fake()->word(),
            'montant' => fake()->randomFloat(2, -5000, 5000),
            'tiers_id' => null,
            'operation_id' => null,
            'seance' => null,
            'date' => $exerciceService->dateRange($exercice)['end']->toDateString(),
            'notes' => null,
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
            'saisi_par' => User::factory(),
        ];
    }
}
