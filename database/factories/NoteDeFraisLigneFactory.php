<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compte;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NoteDeFraisLigne>
 */
final class NoteDeFraisLigneFactory extends Factory
{
    protected $model = NoteDeFraisLigne::class;

    public function definition(): array
    {
        return [
            'note_de_frais_id' => NoteDeFrais::factory(),
            'compte_id' => Compte::factory()->depense(),
            'operation_id' => null,
            'seance' => null,
            'libelle' => fake()->optional(0.7)->sentence(3),
            'montant' => fake()->randomFloat(2, 5, 500),
            'piece_jointe_path' => null,
        ];
    }
}
