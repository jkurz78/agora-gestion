<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class QuestionnaireOcrDraftFactory extends Factory
{
    protected $model = QuestionnaireOcrDraft::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'scan_id' => QuestionnairePaperScan::factory(),
            'invitation_id' => null,
            'payload' => [],
            'statut' => 'brouillon',
        ];
    }
}
