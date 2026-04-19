<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\EmargementDocumentHandler;
use App\Services\Emargement\QrExtractionResult;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

function makeEmargementIncomingFile(string $originalName = 'scan.pdf', string $senderEmail = 'copieur@test.fr', string $source = 'email', string $content = '%PDF-1.4 fake content'): IncomingDocumentFile
{
    $tempPath = storage_path('app/private/temp/em-handler-'.uniqid().'.pdf');
    @mkdir(dirname($tempPath), 0755, true);
    file_put_contents($tempPath, $content);

    return new IncomingDocumentFile(
        tempPath: $tempPath,
        originalFilename: $originalName,
        source: $source,
        senderEmail: $senderEmail,
        recipientEmail: 'emargement@test.fr',
        subject: 'Scan',
        receivedAt: new DateTimeImmutable('2026-04-08 10:00:00'),
        sourceMessageId: null,
    );
}

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
});

afterEach(function () {
    TenantContext::clear();
});

it('passes non-PDF files to the next handler', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldNotReceive('extractSeanceIdFromPdf');

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile('document.docx', content: 'PK plain docx bytes'));

    expect($attempt->outcome)->toBe('pass');
});

it('passes PDFs without QR to the next handler', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('qr_not_found'));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('pass');
});

it('passes PDFs with a non-emargement QR to the next handler', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('qr_unreadable', 'Contenu inattendu'));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('pass');
});

it('fails with pdf_unreadable when the PDF cannot be rasterized', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('pdf_unreadable', 'ghostscript error'));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('failed');
    expect($attempt->failureReason)->toBe('pdf_unreadable');
});

it('fails with qr_wrong_environment when the QR env mismatches', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('qr_wrong_environment', 'QR détecté : emargement:production:42'));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('failed');
    expect($attempt->failureReason)->toBe('qr_wrong_environment');
});

it('fails with qr_no_matching_seance when the QR points to an unknown seance', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok(9999));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('failed');
    expect($attempt->failureReason)->toBe('qr_no_matching_seance');
});

it('attaches the feuille to the target seance when the QR matches', function () {
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
    ]);

    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($seance->id));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('handled');
    expect($attempt->context)->toBe(['seance_id' => $seance->id]);

    $seance->refresh();
    expect($seance->feuille_signee_path)->toBe('feuille-signee.pdf');
    expect($seance->feuille_signee_source)->toBe('email');
    expect($seance->feuille_signee_sender_email)->toBe('copieur@test.fr');
    Storage::disk('local')->assertExists($seance->feuilleSigneeFullPath());
});

it('sets source to manual when the incoming file source is not email', function () {
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
    ]);

    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($seance->id));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile(source: 'manual-inbox'));

    expect($attempt->outcome)->toBe('handled');
    $seance->refresh();
    expect($seance->feuille_signee_source)->toBe('manual');
});

it('overwrites a previously attached feuille on rescan', function () {
    $operation = Operation::factory()->create();
    $seance = Seance::create([
        'operation_id' => $operation->id,
        'numero' => 1,
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now()->subDay(),
        'feuille_signee_source' => 'manual',
    ]);
    Storage::disk('local')->put($seance->feuilleSigneeFullPath(), 'old content');

    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($seance->id));

    $handler = new EmargementDocumentHandler($extractor);
    $attempt = $handler->tryHandle(makeEmargementIncomingFile());

    expect($attempt->outcome)->toBe('handled');
    $seance->refresh();
    expect($seance->feuille_signee_source)->toBe('email');
    expect($seance->feuille_signee_path)->toBe('feuille-signee.pdf');
});
