<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compte;
use App\Models\TypeOperation;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeOperation>
 */
class TypeOperationFactory extends Factory
{
    protected $model = TypeOperation::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'nom' => fake()->unique()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            // DC-10a : la ventilation par défaut est un compte de produits (classe 7).
            'compte_id' => fn (array $attrs) => Compte::firstOrCreate(
                [
                    'association_id' => $attrs['association_id'] ?? (TenantContext::currentId() ?? 1),
                    'numero_pcg' => '706'.fake()->unique()->numerify('##'),
                ],
                [
                    'intitule' => 'Recettes '.fake()->word(),
                    'classe' => 7,
                    'actif' => true,
                ]
            )->id,
            'nombre_seances' => fake()->optional()->numberBetween(5, 30),
            'formulaire_parcours_therapeutique' => false,
            'formulaire_actif' => false,
            'reserve_adherents' => false,
            'actif' => true,
            'logo_path' => null,
        ];
    }

    public function confidentiel(): static
    {
        return $this->state([
            'formulaire_parcours_therapeutique' => true,
            'formulaire_actif' => true,
        ]);
    }

    public function reserveAdherents(): static
    {
        return $this->state(['reserve_adherents' => true]);
    }

    public function inactif(): static
    {
        return $this->state(['actif' => false]);
    }
}
