<?php

declare(strict_types=1);

use App\Enums\StatutInvitation;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireCampaignQuestion;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\Questionnaire\Contracts\QrDecoderContract;
use App\Services\Questionnaire\QuestionnaireScanDocumentHandler;

it('passe si le fichier n est pas un PDF ou une image', function (): void {
    $handler = app(QuestionnaireScanDocumentHandler::class);

    $file = new IncomingDocumentFile(
        tempPath: '/tmp/nonexistent.txt',
        originalFilename: 'document.txt',
        source: 'email',
        senderEmail: 'test@example.com',
        recipientEmail: null,
        subject: 'Test',
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    $result = $handler->tryHandle($file);
    expect($result->outcome)->toBe('pass');
});

it('passe si aucun QR questionnaire n est trouvé', function (): void {
    // Mock decoder to return null
    $this->app->bind(QrDecoderContract::class, function () {
        $mock = Mockery::mock(QrDecoderContract::class);
        $mock->shouldReceive('decodeFromPath')->andReturn(null);

        return $mock;
    });

    $handler = app(QuestionnaireScanDocumentHandler::class);

    // Create a temp file so isImageOrPdf can detect its MIME
    $tmpFile = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($tmpFile, file_get_contents(base_path('public/favicon.ico')) ?: 'PNG');

    $file = new IncomingDocumentFile(
        tempPath: $tmpFile,
        originalFilename: 'scan.png',
        source: 'email',
        senderEmail: 'test@example.com',
        recipientEmail: null,
        subject: 'Questionnaire',
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    $result = $handler->tryHandle($file);
    expect($result->outcome)->toBe('pass');

    @unlink($tmpFile);
});

it('handled quand le QR questionnaire est résolu vers une invitation', function (): void {
    // Create invitation fixture
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

    // Mock decoder to return the token
    $this->app->bind(QrDecoderContract::class, function () use ($tokenClair) {
        $mock = Mockery::mock(QrDecoderContract::class);
        $mock->shouldReceive('decodeFromPath')->andReturn($tokenClair);

        return $mock;
    });

    $handler = app(QuestionnaireScanDocumentHandler::class);

    $tmpFile = tempnam(sys_get_temp_dir(), 'scan');
    file_put_contents($tmpFile, 'fake-png-content');

    $file = new IncomingDocumentFile(
        tempPath: $tmpFile,
        originalFilename: 'questionnaire-scan.png',
        source: 'email',
        senderEmail: 'test@example.com',
        recipientEmail: null,
        subject: 'Retour questionnaire',
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    $result = $handler->tryHandle($file);
    expect($result->outcome)->toBe('handled');
    expect($result->context)->toHaveKey('scan_id');
    expect($result->context)->toHaveKey('campaign_id');

    @unlink($tmpFile);
});
