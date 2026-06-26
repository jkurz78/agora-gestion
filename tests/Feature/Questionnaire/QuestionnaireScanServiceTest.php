<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\Contracts\QrDecoderContract;
use App\Services\Questionnaire\QuestionnaireQrDecoder;
use App\Services\Questionnaire\QuestionnaireScanService;
use App\Support\CurrentAssociation;
use Illuminate\Http\UploadedFile;

it('extractTokenFromUrl extrait le token d une URL /q/{token}', function (): void {
    $decoder = new QuestionnaireQrDecoder;
    $method = new ReflectionMethod(QuestionnaireQrDecoder::class, 'extractTokenFromUrl');

    $token = $method->invoke($decoder, 'https://asso.example.com/q/AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234567');
    expect($token)->toBe('AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234567');
});

it('extractTokenFromUrl retourne null pour une URL sans /q/', function (): void {
    $decoder = new QuestionnaireQrDecoder;
    $method = new ReflectionMethod(QuestionnaireQrDecoder::class, 'extractTokenFromUrl');

    expect($method->invoke($decoder, 'https://example.com/other/path'))->toBeNull();
    expect($method->invoke($decoder, 'just plain text'))->toBeNull();
});

it('ingererUpload crée un scan en_attente sans QR', function (): void {
    // Bind a mock of the interface (interface can be mocked, final class cannot)
    $this->app->bind(QrDecoderContract::class, function () {
        $mock = Mockery::mock(QrDecoderContract::class);
        $mock->shouldReceive('decodeFromPath')->andReturn(null);

        return $mock;
    });

    $file = UploadedFile::fake()->image('scan.png', 800, 1200);

    $scan = app(QuestionnaireScanService::class)->ingererUpload($file);

    expect($scan)->toBeInstanceOf(QuestionnairePaperScan::class);
    expect($scan->source)->toBe('upload');
    expect($scan->qr_statut)->toBe('illisible');
    expect($scan->statut)->toBe('en_attente');
    expect($scan->invitation_id)->toBeNull();
});

it('ingererUpload rattache le scan quand le QR contient un token valide', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1', 'type' => 'texte_court', 'ordre' => 1,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);

    $tokenClair = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234567';
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', $tokenClair),
        'token_chiffre' => $tokenClair,
        'code_court' => 'ABCD1234',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    // Mock the interface to return the known token
    $this->app->bind(QrDecoderContract::class, function () use ($tokenClair) {
        $mock = Mockery::mock(QrDecoderContract::class);
        $mock->shouldReceive('decodeFromPath')->andReturn($tokenClair);

        return $mock;
    });

    $file = UploadedFile::fake()->image('scan.png', 800, 1200);
    $scan = app(QuestionnaireScanService::class)->ingererUpload($file);

    expect($scan->qr_statut)->toBe('detecte');
    expect($scan->statut)->toBe('rattache');
    expect((int) $scan->invitation_id)->toBe((int) $invitation->id);
    expect((int) $scan->campaign_id)->toBe((int) $campagne->id);
});

it('ingererUpload crée un brouillon OCR quand la clé API est configurée', function (): void {
    $op = Operation::factory()->create();
    $campagne = QuestionnaireCampaign::factory()->for($op, 'operation')->create(['statut' => 'ouverte']);
    QuestionnaireCampaignQuestion::factory()->for($campagne, 'campaign')->create([
        'libelle' => 'Q1', 'type' => 'satisfaction', 'ordre' => 1,
    ]);
    $participant = Participant::factory()->create(['operation_id' => $op->id]);

    $tokenClair = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345678901234567';
    $invitation = $campagne->invitations()->create([
        'association_id' => $campagne->association_id,
        'participant_id' => $participant->id,
        'token_hash' => hash('sha256', $tokenClair),
        'token_chiffre' => $tokenClair,
        'code_court' => 'ABCD1234',
        'statut' => StatutInvitation::NonOuvert,
    ]);

    // Configure API key on the association (makes isConfigured() return true)
    $association = CurrentAssociation::tryGet();
    $association->update(['anthropic_api_key' => 'test-key-for-ocr']);

    // Force demo mode so the OCR returns a stub without calling the real API
    app()->detectEnvironment(fn () => 'demo');

    // Mock the interface to return the known token
    $this->app->bind(QrDecoderContract::class, function () use ($tokenClair) {
        $mock = Mockery::mock(QrDecoderContract::class);
        $mock->shouldReceive('decodeFromPath')->andReturn($tokenClair);

        return $mock;
    });

    $file = UploadedFile::fake()->image('scan.png', 800, 1200);
    $scan = app(QuestionnaireScanService::class)->ingererUpload($file);

    // OCR draft should exist
    $draft = QuestionnaireOcrDraft::where('scan_id', $scan->id)->first();
    expect($draft)->not->toBeNull();
    expect($draft->statut)->toBe('brouillon');
    expect($draft->payload)->toBeArray();

    // Restore env
    app()->detectEnvironment(fn () => 'testing');
});
