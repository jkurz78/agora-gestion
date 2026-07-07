<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatutOperation;
use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    public function definition(): array
    {
        $range = app(ExerciceService::class)->dateRange(app(ExerciceService::class)->current());
        $start = fake()->dateTimeBetween($range['start']->toDateString(), $range['end']->subMonth()->toDateString());
        $end = fake()->dateTimeBetween($start, $range['end']->toDateString());

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'nom' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'date_debut' => $start,
            'date_fin' => $end,
            'nombre_seances' => null,
            'statut' => StatutOperation::EnCours,
            'type_operation_id' => TypeOperation::factory(),
        ];
    }

    public function withSeances(int $n): static
    {
        return $this->state(fn () => [
            'nombre_seances' => $n,
        ]);
    }

    public function cloturee(): static
    {
        return $this->state(fn () => [
            'statut' => StatutOperation::Cloturee,
        ]);
    }
}
