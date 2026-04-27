<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Devis>
 */
final class DevisFactory extends Factory
{
    protected $model = Devis::class;

    public function definition(): array
    {
        $dateEmission = Carbon::today();
        $exercice = app(ExerciceService::class)->anneeForDate($dateEmission->toImmutable());

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'numero' => null,
            'tiers_id' => Tiers::factory(),
            'date_emission' => $dateEmission,
            'date_validite' => $dateEmission->copy()->addDays(30),
            'libelle' => null,
            'statut' => StatutDevis::Brouillon,
            'montant_total' => '0.00',
            'exercice' => $exercice,
            'accepte_par_user_id' => null,
            'accepte_le' => null,
            'refuse_par_user_id' => null,
            'refuse_le' => null,
            'annule_par_user_id' => null,
            'annule_le' => null,
            'saisi_par_user_id' => null,
        ];
    }

    public function brouillon(): static
    {
        return $this->state(['statut' => StatutDevis::Brouillon, 'numero' => null]);
    }

    public function valide(): static
    {
        $numero = 'D-'.date('Y').'-'.str_pad((string) fake()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(['statut' => StatutDevis::Valide, 'numero' => $numero]);
    }

    public function accepte(): static
    {
        $numero = 'D-'.date('Y').'-'.str_pad((string) fake()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(fn (array $attributes) => [
            'statut' => StatutDevis::Accepte,
            'numero' => $numero,
            'accepte_par_user_id' => User::factory(),
            'accepte_le' => now(),
        ]);
    }

    public function refuse(): static
    {
        $numero = 'D-'.date('Y').'-'.str_pad((string) fake()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state(fn (array $attributes) => [
            'statut' => StatutDevis::Refuse,
            'numero' => $numero,
            'refuse_par_user_id' => User::factory(),
            'refuse_le' => now(),
        ]);
    }

    public function annule(): static
    {
        return $this->state(fn (array $attributes) => [
            'statut' => StatutDevis::Annule,
            'annule_par_user_id' => User::factory(),
            'annule_le' => now(),
        ]);
    }
}
