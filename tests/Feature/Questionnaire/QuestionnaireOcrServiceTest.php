<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\Questionnaire\QuestionnaireOcrService;
use App\Support\CurrentAssociation;
use Illuminate\Support\Facades\Http;

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

it('remplace les valeurs ressenti du LLM par la mesure pixel', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Premier ressenti', 'type' => 'ressenti', 'ordre' => 1,
    ]);
    $q2 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Second ressenti', 'type' => 'ressenti', 'ordre' => 2,
    ]);
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    // Le LLM renvoie 50 partout — la mesure pixel doit primer
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode([
            (string) $q1->id => ['value' => 50, 'confidence' => 0.6],
            (string) $q2->id => ['value' => 50, 'confidence' => 0.6],
        ]),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    // Scan réel : barres à 17,4 % et 21,6 %
    expect($resultat[(string) $q1->id]['value'])->toBeGreaterThanOrEqual(16)->toBeLessThanOrEqual(19);
    expect($resultat[(string) $q1->id]['confidence'])->toBe(0.98);
    expect($resultat[(string) $q2->id]['value'])->toBeGreaterThanOrEqual(20)->toBeLessThanOrEqual(23);
    expect($resultat[(string) $q2->id]['confidence'])->toBe(0.98);
});

it('conserve les valeurs LLM quand le nombre de barres ne correspond pas', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $questions = collect([1, 2, 3])->map(fn (int $ordre) => QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create(['libelle' => "Ressenti {$ordre}", 'type' => 'ressenti', 'ordre' => $ordre]));
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    // 3 questions ressenti mais le scan ne contient que 2 barres => fail-safe
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode($questions->mapWithKeys(fn ($q, $i) => [
            (string) $q->id => ['value' => 35 + 10 * $i, 'confidence' => 0.6],
        ])->all()),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    expect($resultat[(string) $questions[0]->id]['value'])->toBe(35);
    expect($resultat[(string) $questions[0]->id]['confidence'])->toBe(0.6);
    expect($resultat[(string) $questions[2]->id]['value'])->toBe(55);
});

it('ne modifie pas le payload quand la campagne n a pas de question ressenti', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    $q1 = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Note', 'type' => 'satisfaction', 'ordre' => 1,
    ]);
    CurrentAssociation::tryGet()->update(['anthropic_api_key' => 'test-key-for-ocr']);

    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [[
        'type' => 'text',
        'text' => json_encode([(string) $q1->id => ['value' => 4, 'confidence' => 0.9]]),
    ]]])]);

    $resultat = app(QuestionnaireOcrService::class)->analyzeFromPath(
        base_path('tests/fixtures/questionnaire/ressenti-scan-bars.png'),
        'image/png',
        $campagne->fresh(),
    );

    expect($resultat[(string) $q1->id]['value'])->toBe(4);
    expect($resultat[(string) $q1->id]['confidence'])->toBe(0.9);
});

it('demoStub retourne des valeurs pour les 5 nouveaux types', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);

    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Date', 'type' => 'date', 'ordre' => 1,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Choix', 'type' => 'choix_multiple', 'ordre' => 2,
        'config' => ['options' => [['valeur' => 'a', 'libelle' => 'Option A'], ['valeur' => 'b', 'libelle' => 'Option B']]],
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Nombre', 'type' => 'nombre', 'ordre' => 3,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Email', 'type' => 'email', 'ordre' => 4,
    ]);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Sélection', 'type' => 'selection_numerique', 'ordre' => 5,
        'config' => ['min' => 1, 'max' => 99],
    ]);

    app()->detectEnvironment(fn (): string => 'demo');

    $result = app(QuestionnaireOcrService::class)->analyzeFromPath('/tmp/fake.png', 'image/png', $campagne->fresh());

    expect($result)->toHaveCount(5);

    $values = array_column($result, 'value');
    expect($values)->toContain('2026-01-15');    // date
    expect($values)->toContain(['a']);            // choix_multiple = first option
    expect($values)->toContain(42);              // nombre
    expect($values)->toContain('exemple@email.fr'); // email
    expect($values)->toContain(50);              // selection_numerique midpoint (1+99)/2=50

    app()->detectEnvironment(fn (): string => 'testing');
});
