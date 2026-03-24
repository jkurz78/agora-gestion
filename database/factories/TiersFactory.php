<?php

// database/factories/TiersFactory.php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tiers;
use Illuminate\Database\Eloquent\Factories\Factory;

final class TiersFactory extends Factory
{
    protected $model = Tiers::class;

    public function definition(): array
    {
        $type = 'particulier';

        return [
            'type' => $type,
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'entreprise' => null,
            'email' => fake()->optional()->safeEmail(),
            'telephone' => fake()->optional()->phoneNumber(),
            'adresse_ligne1' => fake()->optional()->address(),
            'code_postal' => fake()->optional()->postcode(),
            'ville' => fake()->optional()->city(),
            'pour_depenses' => fake()->boolean(60),
            'pour_recettes' => fake()->boolean(40),
            'est_helloasso' => false,
        ];
    }

    public function pourDepenses(): static
    {
        return $this->state(['pour_depenses' => true]);
    }

    public function pourRecettes(): static
    {
        return $this->state(['pour_recettes' => true]);
    }

    public function membre(): static
    {
        return $this->state([
            'type' => 'particulier',
            'prenom' => fake()->firstName(),
            'pour_depenses' => false,
            'pour_recettes' => false,
        ]);
    }

    public function entreprise(): static
    {
        return $this->state([
            'type' => 'entreprise',
            'nom' => null,
            'prenom' => null,
            'entreprise' => fake()->company(),
        ]);
    }

    public function avecHelloasso(): static
    {
        return $this->state(['est_helloasso' => true])
            ->afterMaking(function (Tiers $tiers) {
                $tiers->helloasso_nom ??= $tiers->nom;
                $tiers->helloasso_prenom ??= $tiers->prenom;
            });
    }
}
