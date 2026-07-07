<?php

declare(strict_types=1);

use App\Models\QuestionnaireBearerToken;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireSubmission;
use Illuminate\Database\QueryException;

it('crée un bearer token avec hash unique', function (): void {
    $campaign = QuestionnaireCampaign::factory()->create();
    $token = QuestionnaireBearerToken::factory()->create([
        'campaign_id' => $campaign->id,
        'association_id' => $campaign->association_id,
        'token_hash' => hash('sha256', 'test-secret-value'),
    ]);

    expect($token)->toBeInstanceOf(QuestionnaireBearerToken::class);
    expect(strlen($token->token_hash))->toBe(64);
    expect((int) $token->campaign_id)->toBe((int) $campaign->id);
});

it('refuse deux bearer tokens avec le même hash', function (): void {
    $campaign = QuestionnaireCampaign::factory()->create();
    $hash = hash('sha256', 'duplicate-secret');

    QuestionnaireBearerToken::factory()->create([
        'campaign_id' => $campaign->id,
        'association_id' => $campaign->association_id,
        'token_hash' => $hash,
    ]);

    expect(fn () => QuestionnaireBearerToken::factory()->create([
        'campaign_id' => $campaign->id,
        'association_id' => $campaign->association_id,
        'token_hash' => $hash,
    ]))->toThrow(QueryException::class);
});

it('une submission peut être liée à un bearer token sans invitation', function (): void {
    $bearer = QuestionnaireBearerToken::factory()->create();

    $submission = QuestionnaireSubmission::factory()->create([
        'campaign_id' => $bearer->campaign_id,
        'association_id' => $bearer->association_id,
        'invitation_id' => null,
        'bearer_token_id' => $bearer->id,
    ]);

    expect($submission->invitation_id)->toBeNull();
    expect((int) $submission->bearer_token_id)->toBe((int) $bearer->id);

    $loaded = $submission->bearerToken;
    expect($loaded)->not->toBeNull();
    expect((int) $loaded->id)->toBe((int) $bearer->id);
});
