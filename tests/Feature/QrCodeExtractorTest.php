<?php

declare(strict_types=1);

use App\Services\Emargement\QrCodeExtractor;
use Tests\Support\GenerateTestPdf;

beforeEach(function () {
    $this->extractor = new QrCodeExtractor;
    $this->tempDir = storage_path('app/private/temp/test-fixtures');
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        array_map('unlink', glob($this->tempDir.'/*.pdf') ?: []);
    }
});

it('extracts a valid seance id from a well-formed PDF', function () {
    $pdfPath = $this->tempDir.'/valid.pdf';
    file_put_contents($pdfPath, GenerateTestPdf::withEmargementQr(42));

    $result = $this->extractor->extractSeanceIdFromPdf($pdfPath);

    expect($result->seanceId)->toBe(42);
    expect($result->reason)->toBe('ok');
    expect($result->detail)->toBeNull();
});

it('returns qr_not_found when the PDF has no QR', function () {
    $pdfPath = $this->tempDir.'/noqr.pdf';
    file_put_contents($pdfPath, GenerateTestPdf::withoutQr());

    $result = $this->extractor->extractSeanceIdFromPdf($pdfPath);

    expect($result->seanceId)->toBeNull();
    expect($result->reason)->toBe('qr_not_found');
});

it('returns pdf_unreadable when the PDF is corrupted', function () {
    $pdfPath = $this->tempDir.'/corrupted.pdf';
    file_put_contents($pdfPath, 'not a real pdf at all');

    $result = $this->extractor->extractSeanceIdFromPdf($pdfPath);

    expect($result->seanceId)->toBeNull();
    expect($result->reason)->toBe('pdf_unreadable');
});

it('returns qr_wrong_environment when the QR env mismatches', function () {
    $pdfPath = $this->tempDir.'/wrong-env.pdf';
    file_put_contents($pdfPath, GenerateTestPdf::withEmargementQr(42, 'production'));

    $result = $this->extractor->extractSeanceIdFromPdf($pdfPath);

    expect($result->seanceId)->toBeNull();
    expect($result->reason)->toBe('qr_wrong_environment');
});
