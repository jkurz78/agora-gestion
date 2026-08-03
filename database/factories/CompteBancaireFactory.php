<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompteBancaire;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompteBancaire>
 */
class CompteBancaireFactory extends Factory
{
    protected $model = CompteBancaire::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'nom' => fake()->company().' - '.fake()->randomElement(['Courant', 'Livret A', 'Épargne']),
            'iban' => fake()->iban('FR'),
            // Aucun solde historique par défaut : un solde non nul est une
            // situation comptable particulière — une association qui reprend son
            // existant — et non le cas ordinaire. Le tirage aléatoire précédent
            // fabriquait cette situation dans des centaines de tests qui ne la
            // demandaient pas, et fera échouer la garde de reprise des soldes dès
            // qu'elle sera branchée sur la clôture.
            'solde_initial' => 0,
            'date_solde_initial' => fake()->date(),
            'actif_recettes_depenses' => true,
            'saisie_automatisee' => false,
        ];
    }

    /** Association qui reprend un existant : solde historique à la date donnée. */
    public function avecSoldeHistorique(float $montant = 2388.82, string $date = '2024-08-31'): static
    {
        return $this->state(fn (): array => [
            'solde_initial' => $montant,
            'date_solde_initial' => $date,
        ]);
    }
}
