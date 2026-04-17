<?php

declare(strict_types=1);

use App\Http\Controllers\IncomingDocumentsController;
use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use App\Services\IncomingDocuments\IncomingDocumentFile;
use App\Services\IncomingDocuments\IncomingDocumentIngester;
use App\Tenant\TenantContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

// ── IncomingDocumentIngester ──────────────────────────────────────────────────

it('ingest() place le fichier sous associations/{aid}/incoming-documents/{filename}', function () {
    $aid = $this->association->id;
    $tempFile = tempnam(sys_get_temp_dir(), 'ingestion_');
    file_put_contents($tempFile, '%PDF-1.4 fake content');

    $file = new IncomingDocumentFile(
        tempPath: $tempFile,
        originalFilename: 'facture.pdf',
        source: 'email',
        senderEmail: 'sender@test.fr',
        recipientEmail: 'recipient@assoc.fr',
        subject: 'Facture mars',
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    $ingester = app(IncomingDocumentIngester::class);
    $result = $ingester->ingest($file);

    expect($result->outcome)->toBe('pending');

    $doc = IncomingDocument::latest('id')->first();
    expect($doc)->not->toBeNull();

    // storage_path contient uniquement le basename (pas de slash)
    expect($doc->storage_path)->not->toContain('/');

    // Le fichier existe au bon endroit tenant-scoped
    $expectedDir = "associations/{$aid}/incoming-documents";
    expect(Storage::disk('local')->exists("{$expectedDir}/{$doc->storage_path}"))->toBeTrue();
});

it('storage_path en DB contient uniquement le nom court (pas de préfixe de chemin)', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'ingestion_');
    file_put_contents($tempFile, '%PDF-1.4 fake');

    $file = new IncomingDocumentFile(
        tempPath: $tempFile,
        originalFilename: 'doc.pdf',
        source: 'email',
        senderEmail: 'a@b.fr',
        recipientEmail: null,
        subject: null,
        receivedAt: new DateTimeImmutable,
        sourceMessageId: null,
    );

    app(IncomingDocumentIngester::class)->ingest($file);

    $doc = IncomingDocument::latest('id')->first();
    expect($doc->storage_path)->not->toContain('incoming-documents');
    expect($doc->storage_path)->not->toContain('associations');
    expect(pathinfo($doc->storage_path, PATHINFO_EXTENSION))->toBe('pdf');
});

// ── incomingFullPath() accesseur ──────────────────────────────────────────────

it('incomingFullPath() retourne le chemin tenant-scoped reconstruit', function () {
    $aid = $this->association->id;

    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => 'abc123.pdf',
        'original_filename' => 'test.pdf',
        'sender_email' => 'test@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    expect($doc->incomingFullPath())->toBe("associations/{$aid}/incoming-documents/abc123.pdf");
});

// ── IncomingDocumentsController (download) ───────────────────────────────────

it('download via IncomingDocumentsController sert le bon contenu depuis le chemin tenant-scoped', function () {
    $aid = $this->association->id;
    $filename = 'abc123.pdf';

    Storage::disk('local')->put(
        "associations/{$aid}/incoming-documents/{$filename}",
        'PDF CONTENT TENANT'
    );

    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => $filename,
        'original_filename' => 'facture.pdf',
        'sender_email' => 'sender@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $response = $this->get(route('facturation.documents-en-attente.download', $doc));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('facture.pdf');
});

it('download retourne 404 si le fichier est absent du chemin tenant-scoped', function () {
    $aid = $this->association->id;

    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => 'inexistant.pdf',
        'original_filename' => 'inexistant.pdf',
        'sender_email' => 'sender@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->get(route('facturation.documents-en-attente.download', $doc))
        ->assertNotFound();
});

// ── IncomingDocumentsList::supprimer ──────────────────────────────────────────

it('supprimer efface le fichier physique tenant-scoped et la ligne DB', function () {
    $aid = $this->association->id;
    $filename = 'to-delete.pdf';

    Storage::disk('local')->put(
        "associations/{$aid}/incoming-documents/{$filename}",
        'content'
    );

    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => $filename,
        'original_filename' => 'to-delete.pdf',
        'sender_email' => 'x@x.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::test(IncomingDocumentsList::class)
        ->call('supprimer', $doc->id)
        ->assertOk();

    expect(IncomingDocument::find($doc->id))->toBeNull();
    expect(Storage::disk('local')->exists("associations/{$aid}/incoming-documents/{$filename}"))->toBeFalse();
});
