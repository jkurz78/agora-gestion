<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnairePaperScan;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

final class QuestionnairePaperScanFactory extends Factory
{
    protected $model = QuestionnairePaperScan::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'invitation_id' => null,
            'batch_id' => null,
            'incoming_document_id' => null,
            'source' => 'upload',
            'chemin_fichier' => 'scans/'.fake()->uuid().'.pdf',
            'qr_statut' => 'illisible',
            'statut' => 'en_attente',
        ];
    }
}
