<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutPresence;
use App\Models\Participant;
use App\Models\Presence;
use App\Models\Seance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Presence>
 */
final class PresenceFactory extends Factory
{
    protected $model = Presence::class;

    public function definition(): array
    {
        return [
            'participant_id' => Participant::factory(),
            'seance_id' => Seance::factory(),
            'statut' => fake()->randomElement(StatutPresence::cases())->value,
        ];
    }
}
