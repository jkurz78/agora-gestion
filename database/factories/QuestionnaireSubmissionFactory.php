<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireSubmission;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuestionnaireSubmissionFactory extends Factory
{
    protected $model = QuestionnaireSubmission::class;

    public function definition(): array
    {
        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'invitation_id' => QuestionnaireInvitation::factory(),
            'statut' => 'en_cours',
            'accepte_contact' => false,
            'source' => 'en_ligne',
            'submitted_at' => null,
        ];
    }
}
