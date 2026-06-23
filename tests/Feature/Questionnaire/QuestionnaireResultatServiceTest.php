<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireResultatService;

it('agrège les commentaires d une satisfaction commentée', function (): void {
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1, 'config' => ['commentaire' => true],
    ]);
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'Parfait');
    $svc->finaliser($sub, accepteContact: false);

    $res = app(\App\Services\Questionnaire\QuestionnaireResultatService::class)->pourCampagne($campagne->fresh());

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
