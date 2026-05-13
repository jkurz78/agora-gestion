<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Participant;
use App\Models\Reglement;
use App\Models\Seance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reglement>
 */
final class ReglementFactory extends Factory
{
    protected $model = Reglement::class;

    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'seance_id' => Seance::factory(),
            'montant_prevu' => fake()->randomFloat(2, 10, 200),
        ];
    }
}
