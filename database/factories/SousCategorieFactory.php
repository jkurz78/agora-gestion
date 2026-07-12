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
            // null par défaut : un code_cerfa déclenche l'observer qui matérialise
            // un compte. Les tests qui en ont besoin le posent explicitement.
            'code_cerfa' => null,
        ];
    }

    public function pourDons(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::forceCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Don->value,
        ]));
    }

    public function pourCotisations(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::forceCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Cotisation->value,
        ]));
    }

    public function pourInscriptions(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::forceCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::Inscription->value,
        ]));
    }

    public function pourFraisKilometriques(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::forceCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::FraisKilometriques->value,
        ]));
    }

    public function pourAbandonCreance(): static
    {
        return $this->afterCreating(fn (SousCategorie $sc) => UsageSousCategorie::forceCreate([
            'association_id' => $sc->association_id,
            'sous_categorie_id' => $sc->id,
            'usage' => UsageComptable::AbandonCreance->value,
        ]));
    }
}
