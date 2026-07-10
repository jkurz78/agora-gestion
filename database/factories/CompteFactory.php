<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UsageComptable;
use App\Models\Compte;
use App\Models\UsageSousCategorie;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Compte>
 *
 * Numéros par défaut sur 5 caractères (70001-79999 / 60001-69999) : hors plage
 * des seeders (PlanComptableSeeder pose 706A, 751, 606…) et de SystemeSeeder
 * (classes 4/5), donc sans collision avec l'unicité (association_id, numero_pcg).
 * Un test qui a besoin deux fois du même numéro doit réutiliser l'instance —
 * la factory ne fait pas de firstOrCreate.
 */
class CompteFactory extends Factory
{
    protected $model = Compte::class;

    public function definition(): array
    {
        $sequence = str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'numero_pcg' => '7'.$sequence,
            'intitule' => fake()->words(2, true),
            'classe' => 7,
            'actif' => true,
            'est_systeme' => false,
            'pour_inscriptions' => false,
            'lettrable' => false,
        ];
    }

    /** Compte de charge (classe 6, numéro 6xxxx). */
    public function depense(): static
    {
        $sequence = str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return $this->state([
            'numero_pcg' => '6'.$sequence,
            'classe' => 6,
        ]);
    }

    /** Compte de produit (classe 7) — explicite, identique au défaut. */
    public function recette(): static
    {
        return $this->state([
            'classe' => 7,
        ]);
    }

    /** Numéro exact, classe dérivée du premier caractère ('606' → 6, '754' → 7). */
    public function numero(string $numero): static
    {
        return $this->state([
            'numero_pcg' => $numero,
            'classe' => (int) substr($numero, 0, 1),
        ]);
    }

    public function pourDons(): static
    {
        return $this->afterCreating(fn (Compte $compte) => UsageSousCategorie::firstOrCreate([
            'association_id' => $compte->association_id,
            'compte_id' => $compte->id,
            'usage' => UsageComptable::Don->value,
        ]));
    }

    public function pourCotisations(): static
    {
        return $this->afterCreating(fn (Compte $compte) => UsageSousCategorie::firstOrCreate([
            'association_id' => $compte->association_id,
            'compte_id' => $compte->id,
            'usage' => UsageComptable::Cotisation->value,
        ]));
    }

    public function pourInscriptions(): static
    {
        return $this->state(['pour_inscriptions' => true])
            ->afterCreating(fn (Compte $compte) => UsageSousCategorie::firstOrCreate([
                'association_id' => $compte->association_id,
                'compte_id' => $compte->id,
                'usage' => UsageComptable::Inscription->value,
            ]));
    }

    public function pourFraisKilometriques(): static
    {
        return $this->afterCreating(fn (Compte $compte) => UsageSousCategorie::firstOrCreate([
            'association_id' => $compte->association_id,
            'compte_id' => $compte->id,
            'usage' => UsageComptable::FraisKilometriques->value,
        ]));
    }

    public function pourAbandonCreance(): static
    {
        return $this->afterCreating(fn (Compte $compte) => UsageSousCategorie::firstOrCreate([
            'association_id' => $compte->association_id,
            'compte_id' => $compte->id,
            'usage' => UsageComptable::AbandonCreance->value,
        ]));
    }
}
