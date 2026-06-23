<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Enums\StatutSubmission;
use App\Enums\TypeQuestion;
use App\Exceptions\Questionnaire\ReponseObligatoireException;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;

function makeInvitation(): QuestionnaireInvitation
{
    $campagne = QuestionnaireCampaign::factory()->create();
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => true,
    ]);

    return QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
}

it('get-or-create : une seule soumission active par invitation', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();

    $s1 = $svc->demarrerOuReprendre($invitation);
    $s2 = $svc->demarrerOuReprendre($invitation);

    expect($s1->id)->toBe($s2->id);
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
});

it('enregistre une réponse typée par question', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $question = $invitation->campaign->questions()->first();

    $svc->enregistrerReponse($submission, $question, '4');

    $answer = $submission->fresh()->answers()->first();
    expect($answer->value_integer)->toBe(4);
    expect($answer->value_text)->toBeNull();
});

it('refuse de finaliser tant qu une question obligatoire est vide', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);

    expect(fn () => $svc->finaliser($submission, accepteContact: false))
        ->toThrow(ReponseObligatoireException::class);
});

it('finalise et marque invitation soumis', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');

    $svc->finaliser($submission, accepteContact: true);

    expect($submission->fresh()->statut)->toBe(StatutSubmission::Soumise);
    expect($submission->fresh()->accepte_contact)->toBeTrue();
    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Soumis);
    expect($invitation->fresh()->submitted_at)->not->toBeNull();
});

it('réouverture admin : invitation et soumission repassent en cours, submitted_at nul', function (): void {
    $svc = app(QuestionnaireReponseService::class);
    $invitation = makeInvitation();
    $submission = $svc->demarrerOuReprendre($invitation);
    $svc->enregistrerReponse($submission, $invitation->campaign->questions()->first(), '5');
    $svc->finaliser($submission, accepteContact: false);

    $svc->rouvrir($invitation);

    expect($invitation->fresh()->statut)->toBe(StatutInvitation::Commence);
    expect($invitation->fresh()->submitted_at)->toBeNull();
    expect($submission->fresh()->statut)->toBe(StatutSubmission::EnCours);
    expect($submission->fresh()->submitted_at)->toBeNull();
    // Réponses conservées :
    expect($submission->fresh()->answers)->toHaveCount(1);
});
