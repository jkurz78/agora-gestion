<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Compte;
use App\Models\Provision;
use App\Models\TransactionLigne;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Provision>
 */
final class ProvisionFactory extends Factory
{
    public function definition(): array
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'exercice' => $exercice,
            'transaction_ligne_id' => null,
            'compte_id' => Compte::factory(),
            'libelle' => 'Rattachement '.fake()->word(),
            'montant' => fake()->randomFloat(2, 10, 5000),
            'tiers_id' => null,
            'operation_id' => null,
            'seance' => null,
            'notes' => null,
            'piece_jointe_path' => null,
            'piece_jointe_nom' => null,
            'piece_jointe_mime' => null,
            'saisi_par' => User::factory(),
        ];
    }

    /**
     * Rattachement adossé à une ligne d'origine (CCA/PCA). Le compte vient de la
     * ligne : `compte_id` doit être nul, l'invariant exigeant exactement l'une des
     * deux colonnes (D5). Le montant est celui de la ligne, au débit pour une charge
     * et au crédit pour un produit : le rattachement est tout ou rien (D12).
     */
    public function adossee(TransactionLigne $ligne): static
    {
        return $this->state([
            'transaction_ligne_id' => (int) $ligne->id,
            'compte_id' => null,
            'montant' => max((float) $ligne->debit, (float) $ligne->credit),
        ]);
    }
}
