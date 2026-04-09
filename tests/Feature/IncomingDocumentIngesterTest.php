<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingDocument;
use App\Services\IncomingDocuments\Contracts\DocumentHandler;
use App\Services\IncomingDocuments\HandlerAttempt;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\IncomingDocuments\IncomingDocumentIngester;
use App\Services\IncomingDocuments\IncomingDocumentThumbnailGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GenerateTestPdf;

function makeIncomingFile(string $content = '%PDF-1.4 fake', ?string $messageId = null): IncomingDocumentFile
{
    $tempPath = storage_path('app/private/temp/test-ingester-'.uniqid().'.pdf');
    @mkdir(dirname($tempPath), 0755, true);
    file_put_contents($tempPath, $content);

    return new IncomingDocumentFile(
        tempPath: $tempPath,
        originalFilename: 'test.pdf',
        source: 'email',
        senderEmail: 'test@example.com',
        recipientEmail: null,
        subject: 'Test subject',
        receivedAt: new DateTimeImmutable,
        sourceMessageId: $messageId,
    );
}

beforeEach(function () {
    Storage::fake('local');

    // The association singleton must exist for the FK.
    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test Association'])->save();
    }
});

it('calls the first handler that returns handled and stops the chain', function () {
    $handler1 = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::handled(['seance_id' => 42]);
        }

        public function name(): string
        {
            return 'test-first';
        }
    };

    $secondCalled = false;
    $handler2 = new class($secondCalled) implements DocumentHandler
    {
        public function __construct(public bool &$called) {}

        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            $this->called = true;

            return HandlerAttempt::handled();
        }

        public function name(): string
        {
            return 'test-second';
        }
    };

    $ingester = new IncomingDocumentIngester([$handler1, $handler2]);
    $result = $ingester->ingest(makeIncomingFile());

    expect($result->outcome)->toBe('handled');
    expect($result->handlerName)->toBe('test-first');
    expect($result->context)->toBe(['seance_id' => 42]);
    expect($secondCalled)->toBeFalse();
    expect(IncomingDocument::count())->toBe(0);
});

it('stops at the first handler that returns failed and parks the document in the inbox', function () {
    $handler = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::failed('qr_unreadable', 'detail text');
        }

        public function name(): string
        {
            return 'test-handler';
        }
    };

    $ingester = new IncomingDocumentIngester([$handler]);
    $result = $ingester->ingest(makeIncomingFile());

    expect($result->outcome)->toBe('pending');
    expect($result->incomingDocument)->not->toBeNull();
    expect($result->incomingDocument->reason)->toBe('qr_unreadable');
    expect($result->incomingDocument->reason_detail)->toBe('detail text');
    expect($result->incomingDocument->handler_attempted)->toBe('test-handler');
});

it('falls through to unclassified inbox when all handlers pass', function () {
    $handler = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::pass();
        }

        public function name(): string
        {
            return 'test-handler';
        }
    };

    $ingester = new IncomingDocumentIngester([$handler]);
    $result = $ingester->ingest(makeIncomingFile());

    expect($result->outcome)->toBe('pending');
    expect($result->incomingDocument->reason)->toBe('unclassified');
    expect($result->incomingDocument->handler_attempted)->toBeNull();
});

it('dedupes incoming documents by source_message_id', function () {
    $handler = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::pass();
        }

        public function name(): string
        {
            return 'test-handler';
        }
    };

    $ingester = new IncomingDocumentIngester([$handler]);

    $result1 = $ingester->ingest(makeIncomingFile(messageId: '<abc@mail>'));
    $result2 = $ingester->ingest(makeIncomingFile(messageId: '<abc@mail>'));

    expect(IncomingDocument::count())->toBe(1);
    expect($result1->incomingDocument->id)->toBe($result2->incomingDocument->id);
});

it('cleans up the temp file after ingestion (success path)', function () {
    $handler = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::handled();
        }

        public function name(): string
        {
            return 'test-handler';
        }
    };

    $ingester = new IncomingDocumentIngester([$handler]);
    $file = makeIncomingFile();
    $tempPath = $file->tempPath;

    expect(file_exists($tempPath))->toBeTrue();
    $ingester->ingest($file);
    expect(file_exists($tempPath))->toBeFalse();
});

it('génère une vignette pour chaque document parqué en inbox', function () {
    // Créer un PDF source via le helper de test existant (renvoie des bytes PDF)
    $pdfBytes = GenerateTestPdf::withoutQr();
    $tempCopy = tempnam(sys_get_temp_dir(), 'ingester-thumb-').'.pdf';
    file_put_contents($tempCopy, $pdfBytes);

    $file = new IncomingDocumentFile(
        tempPath: $tempCopy,
        originalFilename: 'test.pdf',
        source: 'test',
        senderEmail: 'test@test.fr',
        recipientEmail: null,
        subject: null,
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    // Handler qui fait "pass" systématiquement → force le parking
    $handler = new class implements DocumentHandler
    {
        public function tryHandle(IncomingDocumentFile $file): HandlerAttempt
        {
            return HandlerAttempt::pass();
        }

        public function name(): string
        {
            return 'test-handler';
        }
    };

    // Instancier le ingester avec un vrai generator
    $ingester = new IncomingDocumentIngester(
        handlers: [$handler],
        thumbnailGenerator: app(IncomingDocumentThumbnailGenerator::class),
    );

    $result = $ingester->ingest($file);

    expect($result->outcome)->toBe('pending')
        ->and($result->incomingDocument)->not->toBeNull();

    // Vérifier que la vignette existe au chemin conventionnel
    $thumbPath = IncomingDocument::thumbnailPath($result->incomingDocument->storage_path);
    expect(Storage::disk('local')->exists($thumbPath))->toBeTrue();
});
