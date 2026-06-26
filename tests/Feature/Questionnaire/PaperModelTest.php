<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperBatch;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireReponseService;

it('peut créer un batch papier via factory', function (): void {
    $batch = QuestionnairePaperBatch::factory()->create();
    expect($batch)->toBeInstanceOf(QuestionnairePaperBatch::class);
    expect($batch->type)->toBe('scan');
});

it('peut créer un scan papier via factory', function (): void {
    $scan = QuestionnairePaperScan::factory()->create();
    expect($scan)->toBeInstanceOf(QuestionnairePaperScan::class);
    expect($scan->source)->toBe('upload');
    expect($scan->qr_statut)->toBe('illisible');
    expect($scan->statut)->toBe('en_attente');
});

it('peut créer un brouillon OCR via factory', function (): void {
    $draft = QuestionnaireOcrDraft::factory()->create(['payload' => ['1' => ['value' => '4', 'confidence' => 0.9]]]);
    expect($draft)->toBeInstanceOf(QuestionnaireOcrDraft::class);
    expect($draft->payload)->toBeArray();
    expect($draft->statut)->toBe('brouillon');
});

it('le scan a une relation ocrDraft', function (): void {
    $scan = QuestionnairePaperScan::factory()->create();
    $draft = QuestionnaireOcrDraft::factory()->create(['scan_id' => $scan->id, 'association_id' => $scan->association_id]);
    expect($scan->ocrDraft->id)->toBe($draft->id);
});

it('le batch a une relation scans', function (): void {
    $batch = QuestionnairePaperBatch::factory()->create();
    QuestionnairePaperScan::factory()->create(['batch_id' => $batch->id, 'association_id' => $batch->association_id, 'campaign_id' => $batch->campaign_id]);
    expect($batch->scans)->toHaveCount(1);
});

it('demarrerOuReprendre pose active_key à la création', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1', 'type' => 'texte_court', 'ordre' => 1,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);

    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', 'test-token'),
        'token_chiffre' => 'test-token',
        'code_court' => 'ABCD1234',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    $svc = app(QuestionnaireReponseService::class);
    $submission = $svc->demarrerOuReprendre($invitation);

    expect((int) $submission->active_key)->toBe((int) $invitation->id);
});
