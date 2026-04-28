<?php

declare(strict_types=1);

use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Services\InvoiceOcrService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $association = Association::first() ?? Association::factory()->create(['nom' => 'Test Asso']);
    TenantContext::boot($association);
});

afterEach(function () {
    TenantContext::clear();
    app()->detectEnvironment(fn (): string => 'testing');
});

it('returns a static stub result in demo env without calling the API', function (): void {
    app()->detectEnvironment(fn (): string => 'demo');

    $fakeFile = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    $result = app(InvoiceOcrService::class)->analyze($fakeFile);

    expect($result)->toBeInstanceOf(InvoiceOcrResult::class);
    expect($result->montant_total)->toBe(100.0);
    expect($result->tiers_nom)->toBe('Facture exemple');
    expect($result->date)->toBe(now()->format('Y-m-d'));
    expect($result->lignes)->toHaveCount(1);
    expect($result->lignes[0]->montant)->toBe(100.0);
    expect($result->lignes[0]->description)->toBe('Prestation exemple');
});

it('throws OcrNotConfiguredException in non-demo env when no API key is configured', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    // Association has no anthropic_api_key → OcrNotConfiguredException is thrown
    // This proves the demo guard did NOT intercept the call.
    $fakeFile = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    expect(fn () => app(InvoiceOcrService::class)->analyze($fakeFile))
        ->toThrow(OcrNotConfiguredException::class);
});
