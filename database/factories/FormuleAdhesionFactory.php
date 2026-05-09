<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Association;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormuleAdhesion>
 */
final class FormuleAdhesionFactory extends Factory
{
    protected $model = FormuleAdhesion::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? Association::factory(),
            'nom' => 'Adhésion '.$this->faker->word(),
            'description' => $this->faker->optional()->sentence(),
            'mode' => 'exercice',
            'duree_mois' => null,
            'montant_par_defaut' => $this->faker->randomFloat(2, 10, 100),
            'deductible_fiscal' => false,
            'sous_categorie_id' => SousCategorie::factory()->pourCotisations(),
            'actif' => true,
        ];
    }

    public function modeDuree(int $mois = 12): static
    {
        return $this->state(fn () => [
            'mode' => 'duree',
            'duree_mois' => $mois,
        ]);
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['actif' => false]);
    }

    public function deductible(): static
    {
        return $this->state(fn () => ['deductible_fiscal' => true]);
    }
}
