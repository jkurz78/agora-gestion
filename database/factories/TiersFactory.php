<?php

// database/factories/TiersFactory.php
declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

final class TiersFactory extends Factory
{
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
            'adresse' => fake()->optional()->address(),
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
}
