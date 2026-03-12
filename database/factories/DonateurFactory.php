<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Donateur;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donateur>
 */
class DonateurFactory extends Factory
{
    protected $model = Donateur::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->optional(0.7)->safeEmail(),
            'adresse' => fake()->optional(0.5)->address(),
        ];
    }
}
