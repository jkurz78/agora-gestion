<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireSubmission;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireResultatService;

it('agrège les commentaires d une satisfaction commentée', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'Parfait');
    $svc->finaliser($sub, accepteContact: false);

    $res = app(QuestionnaireResultatService::class)->pourCampagne($campagne->fresh());

    expect($res['questions'][0]['moyenne'])->toBe(5.0);
    expect($res['questions'][0]['verbatims'])->toContain('Parfait');
});

it('agrège satisfaction et exclut les soumissions non soumises', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => TypeQuestion::Satisfaction, 'ordre' => 1, 'obligatoire' => false,
    ]);
    $svc = app(QuestionnaireReponseService::class);

    foreach ([5, 3, 4] as $note) {
        $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
        $sub = $svc->demarrerOuReprendre($inv);
        $svc->enregistrerReponse($sub, $q, (string) $note);
        $svc->finaliser($sub, accepteContact: false);
    }
    // une soumission EN COURS (non finalisée) ne doit pas compter
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $svc->demarrerOuReprendre($inv);

    $resultats = app(QuestionnaireResultatService::class)->pourCampagne($campagne->fresh());

    expect($resultats['nb_soumissions'])->toBe(3);
    expect($resultats['questions'][0]['moyenne'])->toBe(4.0); // (5+3+4)/3
});

it('taux basé sur participants de l opération et non sur invitations', function (): void {
    $op = Operation::factory()->create();
    // 5 participants sur l'opération
    Participant::factory()->count(5)->create(['operation_id' => $op->id]);

    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => true]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);

    // 3 soumissions bearer (sans invitation)
    $bearers = QuestionnaireBearerToken::factory()
        ->for($campagne, 'campaign')
        ->count(3)
        ->create();

    foreach ($bearers as $b) {
        QuestionnaireSubmission::factory()->create([
            'campaign_id' => $campagne->id,
            'invitation_id' => null,
            'bearer_token_id' => $b->id,
            'statut' => 'soumise',
            'submitted_at' => now(),
        ]);
    }

    $service = app(QuestionnaireResultatService::class);
    $resultats = $service->pourCampagne($campagne);

    expect($resultats['nb_soumissions'])->toBe(3);
    expect($resultats['taux'])->toBe(60.0); // 3/5 * 100
});

it('taux papier pur sans invitation ne divise pas par zéro', function (): void {
    $op = Operation::factory()->create();
    Participant::factory()->count(2)->create(['operation_id' => $op->id]);
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['anonymise' => true]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);
    // 0 invitations, 0 soumissions

    $resultats = app(QuestionnaireResultatService::class)->pourCampagne($campagne);
    expect($resultats['taux'])->toBe(0.0);
});
