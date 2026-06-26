<?php

declare(strict_types=1);

use App\Enums\TypeQuestion;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireExcelExporter;
use App\Services\Questionnaire\QuestionnaireReponseService;

it('exporte deux colonnes pour une satisfaction commentée', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
        'config' => ['commentaire' => true],
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '4', commentaire: 'Bien');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

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

it('les questions Information sont exclues des colonnes export', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Titre introductif', 'type' => TypeQuestion::Information, 'ordre' => 1,
    ]);
    $qReal = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Satisfaction globale', 'type' => TypeQuestion::TexteCourt, 'ordre' => 2,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $qReal, 'Bien');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    // L'en-tête doit contenir la vraie question mais pas le bloc Information.
    expect($rows[0])->toContain('Satisfaction globale');
    expect($rows[0])->not->toContain('Titre introductif');
    // 8 colonnes fixes + 1 colonne question réelle = 9 colonnes
    expect($rows[0])->toHaveCount(9);
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

it('satisfaction_texte_long : exporte deux colonnes note + commentaire', function (): void {
    $campagne = QuestionnaireCampaign::factory()->create(['statut' => 'ouverte']);
    $q = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Qualité globale', 'type' => TypeQuestion::SatisfactionTexteLong, 'ordre' => 1,
    ]);
    $svc = app(QuestionnaireReponseService::class);
    $inv = QuestionnaireInvitation::factory()->for($campagne, 'campaign')->create();
    $sub = $svc->demarrerOuReprendre($inv);
    $svc->enregistrerReponse($sub, $q, '5', commentaire: 'Excellent accueil');
    $svc->finaliser($sub, accepteContact: false);

    $rows = app(QuestionnaireExcelExporter::class)->lignes($campagne->fresh());

    // En-têtes : colonne note + colonne commentaire
    expect($rows[0])->toContain('Qualité globale');
    expect($rows[0])->toContain('Qualité globale — commentaire');

    // Données : note entière + texte du commentaire
    expect($rows[1])->toContain(5);
    expect($rows[1])->toContain('Excellent accueil');
});
