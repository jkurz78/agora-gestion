<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Tenant\TenantContext;

function campagneAvecQuestionTypee(string $slug, ?array $config = null): array
{
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte', 'anonymise' => true]);
    $question = QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Test', 'type' => $slug, 'ordre' => 1, 'config' => $config,
    ]);
    $bearer = QuestionnaireBearerToken::create([
        'association_id' => TenantContext::currentId(),
        'campaign_id' => (int) $campagne->id,
        'token_hash' => hash('sha256', 'bearer-'.uniqid()),
    ]);

    return [$campagne, $question, $bearer];
}

it('normalise une date ISO dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('date');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '2026-03-15'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('2026-03-15');
});

it('normalise un choix multiple en JSON dans value_option', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('choix_multiple', [
        'rendu' => 'auto',
        'options' => [
            ['valeur' => 'opt_a', 'libelle' => 'Français', 'ordre' => 1],
            ['valeur' => 'opt_b', 'libelle' => 'Anglais', 'ordre' => 2],
        ],
    ]);

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => ['opt_a', 'opt_b']],
    );

    $answer = $sub->answers()->first();
    expect(json_decode($answer->value_option, true))->toBe(['opt_a', 'opt_b'])
        ->and($answer->value_meta['libelles'])->toBe(['Français', 'Anglais']);
});

it('normalise un nombre décimal dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('nombre');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '37.5'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('37.5');
});

it('normalise un email dans value_text', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('email');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'test@example.com'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_text)->toBe('test@example.com');
});

it('normalise une sélection numérique dans value_integer', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('selection_numerique', ['min' => 18, 'max' => 99]);

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '42'],
    );

    $answer = $sub->answers()->first();
    expect($answer->value_integer)->toBe(42);
});

it('convertit une date d/m/Y en ISO', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('date');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '15/03/2026'],
    );

    expect($sub->answers()->first()->value_text)->toBe('2026-03-15');
});

it('rejette une date invalide ou à débordement (stockage null)', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('date');
    $svc = app(QuestionnaireReponseService::class);

    $sub = $svc->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'illisible'],
    );
    expect($sub->answers()->first()->value_text)->toBeNull();

    $sub2 = $svc->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '2026-13-45'],
    );
    expect($sub2->answers()->first()->value_text)->toBeNull();
});

it('normalise un nombre à virgule française et rejette le non-numérique', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('nombre');
    $svc = app(QuestionnaireReponseService::class);

    $sub = $svc->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => '3,5'],
    );
    expect($sub->answers()->first()->value_text)->toBe('3.5');

    $sub2 = $svc->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 'abc'],
    );
    expect($sub2->answers()->first()->value_text)->toBeNull();
});

it('accepte un nombre float du payload OCR sans TypeError', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('nombre');

    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => 42.5],
    );

    expect($sub->answers()->first()->value_text)->toBe('42.5');
});

it('normalise un choix multiple avec valeurs entières du payload OCR', function (): void {
    [$campagne, $question, $bearer] = campagneAvecQuestionTypee('choix_multiple', [
        'options' => [
            ['valeur' => '1', 'libelle' => 'Un', 'ordre' => 1],
            ['valeur' => '2', 'libelle' => 'Deux', 'ordre' => 2],
        ],
    ]);

    // Le LLM peut renvoyer des entiers JSON pour des valeurs techniques numériques
    $sub = app(QuestionnaireReponseService::class)->creerDepuisOcrAnonyme(
        bearer: $bearer,
        valeursParQuestionId: [(string) $question->id => [1, 2]],
    );

    $answer = $sub->answers()->first();
    expect(json_decode($answer->value_option, true))->toBe(['1', '2'])
        ->and($answer->value_meta['libelles'])->toBe(['Un', 'Deux']);
});
