<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutFacture;
use App\Models\Facture;
use App\Models\Tiers;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Facture>
 */
final class FactureFactory extends Factory
{
    protected $model = Facture::class;

    public function definition(): array
    {
        $date = Carbon::today();
        $exercice = app(ExerciceService::class)->anneeForDate($date->toImmutable());

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'tiers_id' => Tiers::factory(),
            'numero' => null,
            'date' => $date->toDateString(),
            'statut' => StatutFacture::Brouillon,
            'compte_bancaire_id' => null,
            'conditions_reglement' => null,
            'mentions_legales' => null,
            'montant_total' => '0.00',
            'notes' => null,
            'saisi_par' => User::factory(),
            'exercice' => $exercice,
            'devis_id' => null,
            'mode_paiement_prevu' => null,
        ];
    }

    public function validee(): static
    {
        $numero = 'FA-'.date('Y').'-'.str_pad((string) fake()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT);

        return $this->state([
            'statut' => StatutFacture::Validee,
            'numero' => $numero,
            'montant_total' => '100.00',
        ]);
    }

    public function annulee(): static
    {
        return $this->state([
            'statut' => StatutFacture::Annulee,
            'date_annulation' => now()->toDateString(),
        ]);
    }
}
