<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Seance;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\QrExtractionResult;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use App\Services\Emargement\SeanceFeuilleAttacher;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);

    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);

    $this->tempPath = storage_path('app/private/temp/att-'.uniqid().'.pdf');
    @mkdir(dirname($this->tempPath), 0755, true);
    file_put_contents($this->tempPath, '%PDF-1.4 fake');
});

afterEach(function () {
    if (file_exists($this->tempPath)) {
        unlink($this->tempPath);
    }
    TenantContext::clear();
});

it('attaches when QR matches the target seance', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($this->seance->id));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeTrue();
    expect($result->reason)->toBeNull();

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBe("emargement/seance-{$this->seance->id}.pdf");
    expect($this->seance->feuille_signee_source)->toBe('manual');
    expect($this->seance->feuille_signee_sender_email)->toBeNull();
    Storage::disk('local')->assertExists($this->seance->feuille_signee_path);
});

it('rejects with qr_mismatch when QR points to another seance', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok(9999));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeFalse();
    expect($result->reason)->toBe('qr_mismatch');

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBeNull();
});

it('rejects with qr_not_found when no QR is present', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('qr_not_found'));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeFalse();
    expect($result->reason)->toBe('qr_not_found');
});

it('rejects with qr_wrong_environment when env differs', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('qr_wrong_environment', 'emargement:production:42'));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeFalse();
    expect($result->reason)->toBe('qr_wrong_environment');
});

it('rejects with pdf_unreadable when the PDF cannot be rasterized', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::failure('pdf_unreadable', 'ghostscript error'));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeFalse();
    expect($result->reason)->toBe('pdf_unreadable');
});

it('overwrites a previously attached feuille', function () {
    $this->seance->update([
        'feuille_signee_path' => 'emargement/seance-old.pdf',
        'feuille_signee_at' => now()->subDay(),
        'feuille_signee_source' => 'email',
    ]);
    Storage::disk('local')->put('emargement/seance-old.pdf', 'old');

    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($this->seance->id));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($this->tempPath, 'rescan.pdf', $this->seance);

    expect($result->success)->toBeTrue();
    $this->seance->refresh();
    expect($this->seance->feuille_signee_source)->toBe('manual');
    expect($this->seance->feuille_signee_path)->toBe("emargement/seance-{$this->seance->id}.pdf");
});
