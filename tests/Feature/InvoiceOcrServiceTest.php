<?php

declare(strict_types=1);

use App\DTOs\InvoiceOcrLigne;
use App\DTOs\InvoiceOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\InvoiceOcrService;
use App\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('isConfigured retourne false sans clé API', function () {
    expect(InvoiceOcrService::isConfigured())->toBeFalse();
});

it('isConfigured retourne true avec clé API', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    expect(InvoiceOcrService::isConfigured())->toBeTrue();
});

it('analyze lance OcrNotConfiguredException sans clé', function () {
    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrNotConfiguredException::class);

it('analyze parse correctement la réponse API', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => '106',
                    'tiers_id' => null,
                    'tiers_nom' => 'Anne KURZ',
                    'montant_total' => 390.00,
                    'lignes' => [
                        ['description' => 'Séance 4', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => 4, 'montant' => 250.00],
                        ['description' => 'Suivi', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => null, 'montant' => 140.00],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $result = app(InvoiceOcrService::class)->analyze($file);

    expect($result)->toBeInstanceOf(InvoiceOcrResult::class)
        ->and($result->date)->toBe('2025-11-22')
        ->and($result->reference)->toBe('106')
        ->and($result->tiers_nom)->toBe('Anne KURZ')
        ->and($result->montant_total)->toBe(390.00)
        ->and($result->lignes)->toHaveCount(2)
        ->and($result->lignes[0])->toBeInstanceOf(InvoiceOcrLigne::class)
        ->and($result->lignes[0]->montant)->toBe(250.00)
        ->and($result->lignes[0]->seance)->toBe(4);
});

it('analyze gère les warnings de cohérence', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => '106',
                    'tiers_id' => null,
                    'tiers_nom' => 'Anne KURZ',
                    'montant_total' => 390.00,
                    'lignes' => [],
                    'warnings' => ['Le tiers sur la facture (Anne KURZ) ne correspond pas au tiers sélectionné (Jürgen KURZ)'],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    $result = app(InvoiceOcrService::class)->analyze($file, [
        'tiers_attendu' => 'Jürgen KURZ',
    ]);

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('Anne KURZ');
});

it('analyze lance OcrAnalysisException si API échoue', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response('Internal Server Error', 500),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrAnalysisException::class);

it('analyze lance OcrAnalysisException si JSON invalide', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ceci nest pas du json']],
        ]),
    ]);

    $file = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');
    app(InvoiceOcrService::class)->analyze($file);
})->throws(OcrAnalysisException::class);

it('analyzeFromPath parse correctement la réponse API depuis un fichier sur disque', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);
    SousCategorie::factory()->create();

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => 'FAC-42',
                    'tiers_id' => null,
                    'tiers_nom' => 'EDF',
                    'montant_total' => 123.45,
                    'lignes' => [
                        ['description' => 'Électricité', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => null, 'montant' => 123.45],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $tempPath = tempnam(sys_get_temp_dir(), 'invoice-ocr-').'.pdf';
    file_put_contents($tempPath, '%PDF-1.4 fake content');

    try {
        $result = app(InvoiceOcrService::class)->analyzeFromPath($tempPath, 'application/pdf');

        expect($result)->toBeInstanceOf(InvoiceOcrResult::class)
            ->and($result->reference)->toBe('FAC-42')
            ->and($result->tiers_nom)->toBe('EDF')
            ->and($result->montant_total)->toBe(123.45);
    } finally {
        @unlink($tempPath);
    }
});

it('analyzeFromPath lance OcrNotConfiguredException sans clé API', function () {
    $tempPath = tempnam(sys_get_temp_dir(), 'invoice-ocr-').'.pdf';
    file_put_contents($tempPath, '%PDF-1.4 fake');

    try {
        app(InvoiceOcrService::class)->analyzeFromPath($tempPath, 'application/pdf');
    } finally {
        @unlink($tempPath);
    }
})->throws(OcrNotConfiguredException::class);
