<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use App\Services\Questionnaire\QuestionnaireReponseService;

it('produit des en-têtes stables avec colonnes identité même sans consentement', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'titre_affiche' => 'Avis']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '4');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    $entetes = $rows[0];
    expect($entetes)->toContain('Participant (si contact accepté)');
    expect($entetes)->toContain('Note');
    // ligne de données : identité vide (pas de consentement), note = 4
    expect($rows[1])->toContain('');   // colonne identité vide
    expect($rows[1])->toContain(4);
});
