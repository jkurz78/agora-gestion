<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Association;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

final class AssociationFactory extends Factory
{
    protected $model = Association::class;

    public function definition(): array
    {
        $nom = 'Association '.$this->faker->unique()->company();

        return [
            'nom' => $nom,
            'slug' => Str::slug($nom).'-'.Str::random(4),
            'adresse' => $this->faker->streetAddress(),
            'code_postal' => $this->faker->postcode(),
            'ville' => $this->faker->city(),
            'email' => $this->faker->companyEmail(),
            'telephone' => $this->faker->phoneNumber(),
            'exercice_mois_debut' => 9,
            'statut' => 'actif',
            'wizard_completed_at' => now(),
        ];
    }
}
