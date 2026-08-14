<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compte;
use App\Models\Immobilisation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Immobilisation> */
final class ImmobilisationFactory extends Factory
{
    protected $model = Immobilisation::class;

    public function definition(): array
    {
        return [
            'numero' => 'IM'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'libelle' => $this->faker->words(3, true),
            'quantite' => 1,
            'compte_id' => fn (): int => (int) Compte::factory()->create([
                'numero_pcg' => '21'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
                'classe' => 2,
            ])->id,
            'compte_amortissement_id' => fn (): int => (int) Compte::factory()->create([
                'numero_pcg' => '28'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
                'classe' => 2,
            ])->id,
            'montant_acquisition' => '3000.00',
            'date_mise_en_service' => '2026-09-12',
            'duree_mois' => 60,
            'transaction_id' => fn (): int => (int) Transaction::factory()->create()->id,
        ];
    }
}
