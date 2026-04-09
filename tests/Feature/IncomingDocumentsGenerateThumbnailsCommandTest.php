<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GenerateTestPdf;

beforeEach(function () {
    Storage::fake('local');

    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test'])->save();
    }
});

it('génère les vignettes manquantes pour les documents existants', function () {
    // Document avec PDF mais sans vignette (utilise le helper codebase)
    $pdfBytes = GenerateTestPdf::withoutQr();
    Storage::disk('local')->put('incoming-documents/abc.pdf', $pdfBytes);
    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $thumbPath = IncomingDocument::thumbnailPath($doc->storage_path);
    expect(Storage::disk('local')->exists($thumbPath))->toBeFalse();

    $this->artisan('incoming:generate-thumbnails')
        ->expectsOutputToContain('Vignettes générées')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists($thumbPath))->toBeTrue();
});

it('saute les documents qui ont déjà une vignette', function () {
    Storage::disk('local')->put('incoming-documents/xyz.pdf', 'PDF');
    Storage::disk('local')->put('incoming-documents/thumbs/xyz.jpg', 'EXISTING JPEG');

    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/xyz.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->artisan('incoming:generate-thumbnails')
        ->expectsOutputToContain('sautées')
        ->assertSuccessful();

    // La vignette existante n'a pas été écrasée
    expect(Storage::disk('local')->get('incoming-documents/thumbs/xyz.jpg'))->toBe('EXISTING JPEG');
});

it('--force régénère même si la vignette existe déjà', function () {
    $pdfBytes = GenerateTestPdf::withoutQr();
    Storage::disk('local')->put('incoming-documents/force.pdf', $pdfBytes);
    Storage::disk('local')->put('incoming-documents/thumbs/force.jpg', 'OLD JPEG');

    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/force.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->artisan('incoming:generate-thumbnails --force')
        ->assertSuccessful();

    // La vignette a été écrasée (contenu différent de 'OLD JPEG')
    expect(Storage::disk('local')->get('incoming-documents/thumbs/force.jpg'))->not->toBe('OLD JPEG');
});

it('signale les PDFs introuvables sans planter', function () {
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/ghost.pdf', // n'existe pas sur disque
        'original_filename' => 'ghost.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->artisan('incoming:generate-thumbnails')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('incoming-documents/thumbs/ghost.jpg'))->toBeFalse();
});
