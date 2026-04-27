<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeLigneDevis;
use App\Models\Devis;
use App\Models\DevisLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevisLigne>
 */
final class DevisLigneFactory extends Factory
{
    protected $model = DevisLigne::class;

    public function definition(): array
    {
        $prixUnitaire = fake()->randomFloat(2, 10, 500);
        $quantite = fake()->randomFloat(3, 1, 10);
        $montant = round($prixUnitaire * $quantite, 2);

        return [
            'devis_id' => Devis::factory(),
            'ordre' => fake()->numberBetween(1, 20),
            'type' => TypeLigneDevis::Montant,
            'libelle' => fake()->sentence(3),
            'prix_unitaire' => $prixUnitaire,
            'quantite' => $quantite,
            'montant' => $montant,
            'sous_categorie_id' => null,
        ];
    }

    /**
     * Ligne de type montant (par défaut).
     */
    public function montant(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => TypeLigneDevis::Montant,
        ]);
    }

    /**
     * Ligne de type texte (commentaire/titre de section).
     */
    public function texte(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => TypeLigneDevis::Texte,
            'libelle' => fake()->sentence(4),
            'prix_unitaire' => null,
            'quantite' => null,
            'montant' => null,
            'sous_categorie_id' => null,
        ]);
    }
}
