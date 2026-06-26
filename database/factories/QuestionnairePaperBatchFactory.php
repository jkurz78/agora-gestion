<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnairePaperBatch;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class QuestionnairePaperBatchFactory extends Factory
{
    protected $model = QuestionnairePaperBatch::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'type' => 'scan',
            'cree_par' => null,
        ];
    }
}
