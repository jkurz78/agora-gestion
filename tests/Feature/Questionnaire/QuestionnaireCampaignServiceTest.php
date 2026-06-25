<?php

declare(strict_types=1);

use App\Enums\StatutCampagne;
use App\Enums\TypeQuestion;
use App\Models\Operation;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateQuestion;
use App\Services\Questionnaire\QuestionnaireCampaignService;

it('fige un snapshot des questions du modèle dans la campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create(['titre_affiche' => 'Avis', 'remerciement' => 'Merci']);
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Note', 'type' => TypeQuestion::Satisfaction, 'ordre' => 1,
    ]);

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->statut)->toBe(StatutCampagne::Brouillon);
    expect($campagne->titre_affiche)->toBe('Avis');
    expect($campagne->questions)->toHaveCount(1);
    expect($campagne->questions->first()->libelle)->toBe('Note');

    // Modifier le modèle après coup NE change PAS la campagne (snapshot).
    $t->questions()->first()->update(['libelle' => 'MODIFIÉ']);
    expect($campagne->fresh()->questions->first()->libelle)->toBe('Note');
});

it('copie les 3 réglages du modèle dans la campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create([
        'anonymise' => false,
        'autoriser_retour' => false,
        'afficher_progression' => false,
    ]);

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->anonymise)->toBeFalse();
    expect($campagne->autoriser_retour)->toBeFalse();
    expect($campagne->afficher_progression)->toBeFalse();
});

it('les réglages par défaut du snapshot sont true', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create();

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->anonymise)->toBeTrue();
    expect($campagne->autoriser_retour)->toBeTrue();
    expect($campagne->afficher_progression)->toBeTrue();
});

it('snapshot copie grouper_avec_precedente=true du modèle vers la campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create();
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Q groupée', 'ordre' => 1, 'grouper_avec_precedente' => true,
    ]);

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->questions->first()->grouper_avec_precedente)->toBeTrue();
});

it('snapshot copie grouper_avec_precedente=false par défaut', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create();
    QuestionnaireTemplateQuestion::factory()->for($t, 'template')->create([
        'libelle' => 'Q normale', 'ordre' => 1,
    ]);

    $campagne = app(QuestionnaireCampaignService::class)->creerDepuisModele($op, $t);

    expect($campagne->questions->first()->grouper_avec_precedente)->toBeFalse();
});

it('ouvre puis clôture une campagne', function (): void {
    $op = Operation::factory()->create();
    $t = QuestionnaireTemplate::factory()->create();
    $svc = app(QuestionnaireCampaignService::class);
    $campagne = $svc->creerDepuisModele($op, $t);

    $svc->ouvrir($campagne);
    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Ouverte);
    expect($campagne->fresh()->ouverte_at)->not->toBeNull();

    $svc->cloturer($campagne);
    expect($campagne->fresh()->statut)->toBe(StatutCampagne::Cloturee);
});
