<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TypeDocumentPrevisionnel;
use App\Models\DocumentPrevisionnel;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\User;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentPrevisionnel>
 */
final class DocumentPrevisionnelFactory extends Factory
{
    protected $model = DocumentPrevisionnel::class;

    public function definition(): array
    {
        $date = Carbon::today();
        $exercice = app(ExerciceService::class)->anneeForDate($date->toImmutable());
        $type = fake()->randomElement(TypeDocumentPrevisionnel::cases());
        $numero = $type->prefix().'-'.date('Y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'operation_id' => Operation::factory(),
            'participant_id' => Participant::factory(),
            'type' => $type,
            'numero' => $numero,
            'version' => 1,
            'date' => $date->toDateString(),
            'montant_total' => fake()->randomFloat(2, 10, 1000),
            'lignes_json' => [],
            'pdf_path' => null,
            'saisi_par' => User::factory(),
            'exercice' => $exercice,
        ];
    }

    public function devis(): static
    {
        $numero = 'D-'.date('Y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return $this->state([
            'type' => TypeDocumentPrevisionnel::Devis,
            'numero' => $numero,
        ]);
    }

    public function proforma(): static
    {
        $numero = 'PF-'.date('Y').'-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT);

        return $this->state([
            'type' => TypeDocumentPrevisionnel::Proforma,
            'numero' => $numero,
        ]);
    }
}
