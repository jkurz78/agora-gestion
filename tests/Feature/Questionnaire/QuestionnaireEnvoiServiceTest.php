<?php

declare(strict_types=1);

use App\Mail\QuestionnaireInvitationMail;
use App\Models\EmailLog;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireEnvoiService;
use Illuminate\Support\Facades\Mail;

it('envoie aux invitations ciblées, résout le lien, journalise, pose sent_at', function (): void {
    Mail::fake();
    $op = Operation::factory()->create();
    $participant = Participant::factory()->create(['operation_id' => $op->id]);
    $participant->tiers->update(['email' => 'marie@example.test', 'prenom' => 'Marie']);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['participant_id' => $participant->id]);

    app(QuestionnaireEnvoiService::class)->envoyer($campagne, [$inv->id], 'Objet {prenom}', 'Lien : {lien_questionnaire}');

    Mail::assertSent(QuestionnaireInvitationMail::class, 1);
    expect(EmailLog::count())->toBe(1);
    expect($inv->fresh()->sent_at)->not->toBeNull();
});

it('ne vise que les non soumis pour une relance', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['statut' => 'soumis']);
    QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create(['statut' => 'non_ouvert']);

    expect(app(QuestionnaireEnvoiService::class)->idsNonSoumis($campagne))->toHaveCount(1);
});
