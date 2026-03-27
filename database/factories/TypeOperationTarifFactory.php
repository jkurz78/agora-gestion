<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TypeOperationTarif>
 */
class TypeOperationTarifFactory extends Factory
{
    protected $model = TypeOperationTarif::class;

    public function definition(): array
    {
        return [
            'type_operation_id' => TypeOperation::factory(),
            'libelle' => fake()->words(2, true),
            'montant' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
