<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireInvitationService;

it('génère une invitation par participant sélectionné, sans doublon', function (): void {
    $op = Operation::factory()->create();
    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create();

    $svc = app(QuestionnaireInvitationService::class);
    $svc->genererPour($campagne, [$p1->id, $p2->id]);
    $svc->genererPour($campagne, [$p1->id, $p2->id]); // rejeu → pas de doublon

    expect($campagne->fresh()->invitations)->toHaveCount(2);
});
