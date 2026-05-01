<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeTransaction;
use App\Models\Extourne;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extourne>
 */
class ExtourneFactory extends Factory
{
    protected $model = Extourne::class;

    public function definition(): array
    {
        $associationId = TenantContext::currentId();

        $origine = Transaction::factory()->create([
            'association_id' => $associationId,
            'type' => TypeTransaction::Recette,
            'montant_total' => 80,
        ]);

        $extourne = Transaction::factory()->create([
            'association_id' => $associationId,
            'type' => TypeTransaction::Recette,
            'montant_total' => -80,
            'compte_id' => $origine->compte_id,
            'tiers_id' => $origine->tiers_id,
            'libelle' => 'Annulation - '.$origine->libelle,
        ]);

        return [
            'transaction_origine_id' => $origine->id,
            'transaction_extourne_id' => $extourne->id,
            'rapprochement_lettrage_id' => null,
            'association_id' => $associationId,
            'created_by' => User::factory(),
        ];
    }
}
