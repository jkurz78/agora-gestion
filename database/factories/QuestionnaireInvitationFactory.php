<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuestionnaireInvitationFactory extends Factory
{
    protected $model = QuestionnaireInvitation::class;

    public function definition(): array
    {
        $clair = Str::random(48);

        return [
            'association_id' => TenantContext::currentId() ?? 1,
            'campaign_id' => QuestionnaireCampaign::factory(),
            'participant_id' => Participant::factory(),
            'token_hash' => hash('sha256', $clair),
            'token_chiffre' => $clair, // cast 'encrypted' chiffre à l'écriture
            'code_court' => strtoupper(Str::random(8)),
            'statut' => 'non_ouvert',
            'sent_at' => null,
            'opened_at' => null,
            'submitted_at' => null,
        ];
    }
}
