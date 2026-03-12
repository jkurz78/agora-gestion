<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutMembre;
use App\Models\Cotisation;
use App\Models\Membre;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membre>
 */
class MembreFactory extends Factory
{
    protected $model = Membre::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->optional(0.8)->safeEmail(),
            'telephone' => fake()->optional(0.6)->phoneNumber(),
            'adresse' => fake()->optional(0.5)->address(),
            'date_adhesion' => fake()->optional(0.8)->dateTimeBetween('-5 years', 'now'),
            'statut' => StatutMembre::Actif,
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }

    public function inactif(): static
    {
        return $this->state(fn () => ['statut' => StatutMembre::Inactif]);
    }

    public function withCotisation(int $exercice): static
    {
        return $this->afterCreating(function (Membre $membre) use ($exercice) {
            Cotisation::factory()->create([
                'membre_id' => $membre->id,
                'exercice' => $exercice,
            ]);
        });
    }
}
