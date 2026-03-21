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
        $type = fake()->randomElement(['entreprise', 'particulier']);

        return [
            'type' => $type,
            'nom' => $type === 'entreprise'
                ? fake()->company()
                : fake()->lastName(),
            'prenom' => $type === 'particulier' ? fake()->firstName() : null,
            'email' => fake()->optional()->safeEmail(),
            'telephone' => fake()->optional()->phoneNumber(),
            'adresse_ligne1' => fake()->optional()->address(),
            'pour_depenses' => fake()->boolean(60),
            'pour_recettes' => fake()->boolean(40),
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
            'type'          => 'particulier',
            'prenom'        => fake()->firstName(),
            'pour_depenses' => false,
            'pour_recettes' => false,
        ]);
    }
}
