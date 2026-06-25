<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\Questionnaire\QuestionnaireImpressionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Construit une campagne avec questions et participants pour les tests d'impression.
 *
 * @return array{campagne: QuestionnaireCampaign, participantIds: list<int>}
 */
function buildImpressionFixture(): array
{
    $op = Operation::factory()->create();

    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create([
        'titre_affiche' => 'Enquête test impression',
        'statut' => 'ouverte',
    ]);

    // Deux questions dans deux écrans distincts.
    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create([
            'libelle' => 'Question 1',
            'type' => 'texte_court',
            'ordre' => 1,
            'grouper_avec_precedente' => false,
        ]);

    QuestionnaireCampaignQuestion::factory()
        ->for($campagne, 'campaign')
        ->create([
            'libelle' => 'Question 2',
            'type' => 'texte_court',
            'ordre' => 2,
            'grouper_avec_precedente' => false,
        ]);

    $p1 = Participant::factory()->create(['operation_id' => $op->id]);
    $p2 = Participant::factory()->create(['operation_id' => $op->id]);

    return [
        'campagne' => $campagne,
        'participantIds' => [(int) $p1->id, (int) $p2->id],
    ];
}

it('génère une invitation par participant lors du premier appel à construireDonnees', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    expect($campagne->invitations()->count())->toBe(0);

    app(QuestionnaireImpressionService::class)->construireDonnees($campagne, $pids);

    expect($campagne->invitations()->count())->toBe(2);
});

it('chaque invitation générée a un code_court non vide et un token chiffré', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    app(QuestionnaireImpressionService::class)->construireDonnees($campagne, $pids);

    $invitations = $campagne->invitations()->get();
    foreach ($invitations as $inv) {
        expect($inv->code_court)->not->toBeEmpty();
        expect($inv->token_chiffre)->not->toBeEmpty();
    }
});

it('construireDonnees est idempotent : un second appel ne duplique pas les invitations', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $svc = app(QuestionnaireImpressionService::class);
    $svc->construireDonnees($campagne, $pids);
    $svc->construireDonnees($campagne, $pids);

    expect($campagne->invitations()->count())->toBe(2);
});

it('construireDonnees retourne une page par participant sélectionné', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $données = app(QuestionnaireImpressionService::class)->construireDonnees($campagne, $pids);

    expect($données['pages'])->toHaveCount(2);
});

it('chaque page contient un QR code data-URI PNG', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $données = app(QuestionnaireImpressionService::class)->construireDonnees($campagne, $pids);

    foreach ($données['pages'] as $page) {
        expect($page['qr'])->toStartWith('data:image/png;base64,');
    }
});

it('construireDonnees retourne un tableau non-vide de groupes correspondant aux écrans', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $données = app(QuestionnaireImpressionService::class)->construireDonnees($campagne, $pids);

    // 2 questions avec grouper_avec_precedente=false => 2 écrans
    expect($données['groupes'])->toBeArray()->not->toBeEmpty();
    expect(count($données['groupes']))->toBe(2);
});

it('telecharger retourne une StreamedResponse HTTP', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $response = app(QuestionnaireImpressionService::class)->telecharger($campagne, $pids);

    expect($response)->toBeInstanceOf(StreamedResponse::class);
});

it('le contenu du PDF commence par %PDF (rendu DomPDF réel)', function (): void {
    ['campagne' => $campagne, 'participantIds' => $pids] = buildImpressionFixture();

    $response = app(QuestionnaireImpressionService::class)->telecharger($campagne, $pids);

    // StreamedResponse n'expose pas getContent() — on capture le flux.
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toStartWith('%PDF');
});
