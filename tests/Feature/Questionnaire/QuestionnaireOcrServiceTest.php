<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\Questionnaire\QuestionnaireOcrService;

it('parse extrait le JSON de réponses par question', function (): void {
    $svc = app(QuestionnaireOcrService::class);
    $payload = $svc->parse('{"12":{"value":"4","confidence":0.9}}');

    expect($payload)->toHaveKey('12');
    expect($payload['12']['value'])->toBe('4');
    expect($payload['12']['confidence'])->toBe(0.9);
});

it('parse gère les blocs markdown ```json', function (): void {
    $svc = app(QuestionnaireOcrService::class);
    $payload = $svc->parse("```json\n{\"5\":{\"value\":true,\"confidence\":0.8}}\n```");

    expect($payload)->toHaveKey('5');
    expect($payload['5']['value'])->toBe(true);
});

it('parse retourne un tableau vide pour du texte non-JSON', function (): void {
    $svc = app(QuestionnaireOcrService::class);
    expect($svc->parse('not json'))->toBe([]);
});

it('demoStub retourne des valeurs par type de question', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => 'satisfaction', 'ordre' => 1,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Commentaire', 'type' => 'texte_long', 'ordre' => 2,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Titre section', 'type' => 'information', 'ordre' => 3,
    ]);

    app()->detectEnvironment(fn (): string => 'demo');

    $svc = app(QuestionnaireOcrService::class);
    $result = $svc->analyzeFromPath('/tmp/fake.png', 'image/png', $campagne->fresh());

    // Should have 2 entries (satisfaction + texte_long), NOT the information type
    expect($result)->toHaveCount(2);

    app()->detectEnvironment(fn (): string => 'testing');
});
