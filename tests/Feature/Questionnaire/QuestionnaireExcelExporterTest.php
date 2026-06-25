<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use App\Services\Questionnaire\QuestionnaireReponseService;

it('exporte deux colonnes pour une satisfaction commentée', function (): void {
    $campagne = \App\Models\QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = \App\Models\QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => \App\Enums\TypeQuestion::Satisfaction, 'ordre' => 1,
        'config' => ['commentaire' => true],
    ]);
    $svc = app(\App\Services\Questionnaire\QuestionnaireReponseService::class);
    $inv = \App\Models\QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '4', commentaire: 'Bien');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(\App\Services\Questionnaire\QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    expect($rows[0])->toContain('Note');
    expect($rows[0])->toContain('Note — commentaire');
    expect($rows[1])->toContain(4);
    expect($rows[1])->toContain('Bien');
});

it('anonymise=false : l identité est remplie même sans consentement dans l export', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte', 'anonymise' => false, 'titre_affiche' => 'Nominatif']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Avis', 'type' => TypeQuestion::TexteCourt, 'ordre' => 1,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $inv->participant->tiers->update(['prenom' => 'Marie', 'nom' => 'DUPONT']);
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, 'Très bien');
    $svc->finaliser($sub, accepteContact: false); // pas de consentement

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    // Identité doit être remplie malgré l'absence de consentement.
    $identiteCell = $rows[1][7]; // colonne index 7 = « Participant (si contact accepté) »
    expect((string) $identiteCell)->toContain('DUPONT');
});

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
