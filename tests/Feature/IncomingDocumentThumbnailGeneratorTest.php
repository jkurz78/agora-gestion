<?php

declare(strict_types=1);

use App\Services\IncomingDocuments\IncomingDocumentThumbnailGenerator;
use Illuminate\Support\Facades\Storage;
use Tests\Support\GenerateTestPdf;

beforeEach(function () {
    Storage::fake('local');

    $this->tempDir = storage_path('app/private/temp/test-thumbs');
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*') ?: []);
    }
});

it('génère une vignette JPEG pour un PDF valide', function () {
    $sourcePdf = $this->tempDir.'/valid.pdf';
    file_put_contents($sourcePdf, GenerateTestPdf::withoutQr());

    $destPath = Storage::disk('local')->path('incoming-documents/thumbs/test-thumb.jpg');
    @mkdir(dirname($destPath), 0755, true);

    $generator = app(IncomingDocumentThumbnailGenerator::class);
    $result = $generator->generate($sourcePdf, $destPath);

    expect($result)->toBeTrue()
        ->and(file_exists($destPath))->toBeTrue()
        ->and(filesize($destPath))->toBeGreaterThan(100);

    // Vérifier que c'est bien un JPEG
    $mime = mime_content_type($destPath);
    expect($mime)->toBe('image/jpeg');
});

it('retourne false sans exception sur un PDF corrompu', function () {
    $brokenPdf = tempnam(sys_get_temp_dir(), 'broken-').'.pdf';
    file_put_contents($brokenPdf, 'NOT A REAL PDF');

    $destPath = tempnam(sys_get_temp_dir(), 'thumb-').'.jpg';
    @unlink($destPath);

    try {
        $generator = app(IncomingDocumentThumbnailGenerator::class);
        $result = $generator->generate($brokenPdf, $destPath);

        expect($result)->toBeFalse()
            ->and(file_exists($destPath))->toBeFalse();
    } finally {
        @unlink($brokenPdf);
        @unlink($destPath);
    }
});

it('retourne false sans exception sur un PDF source inexistant', function () {
    $destPath = tempnam(sys_get_temp_dir(), 'thumb-').'.jpg';
    @unlink($destPath);

    $generator = app(IncomingDocumentThumbnailGenerator::class);
    $result = $generator->generate('/tmp/does-not-exist-'.uniqid().'.pdf', $destPath);

    expect($result)->toBeFalse()
        ->and(file_exists($destPath))->toBeFalse();
});
