<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageComptable;
use App\Models\Categorie;
use App\Models\SousCategorie;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SousCategorie>
 */
class SousCategorieFactory extends Factory
{
    protected $model = SousCategorie::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'categorie_id' => Categorie::factory(),
            'nom' => fake()->words(2, true),
            'code_cerfa' => fake()->optional(0.3)->numerify('####'),
            'pour_dons' => false,
            'pour_cotisations' => false,
            'pour_inscriptions' => false,
        ];
    }

    public function pourDons(): static
    {
        return $this->state(['pour_dons' => true])
            ->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::firstOrCreate([
                'association_id' => $sc->association_id,
                'sous_categorie_id' => $sc->id,
                'usage' => UsageComptable::Don->value,
            ]));
    }

    public function pourCotisations(): static
    {
        return $this->state(['pour_cotisations' => true])
            ->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::firstOrCreate([
                'association_id' => $sc->association_id,
                'sous_categorie_id' => $sc->id,
                'usage' => UsageComptable::Cotisation->value,
            ]));
    }

    public function pourInscriptions(): static
    {
        return $this->state(fn (array $attributes) => [
            'pour_inscriptions' => true,
        ])->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::firstOrCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Inscription->value,
        ]));
    }

    public function pourFraisKilometriques(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::firstOrCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::FraisKilometriques->value,
        ]));
    }
}
