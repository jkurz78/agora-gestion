<?php

declare(strict_types=1);

use App\DTOs\ReleveOcrResult;
use App\Exceptions\OcrAnalysisException;
use App\Exceptions\OcrNotConfiguredException;
use App\Models\Association;
use App\Services\ReleveOcrService;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
});

afterEach(function (): void {
    TenantContext::clear();
});

it('parse un releve complet', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => 1000.00,
                    'solde_cloture' => 1500.50,
                    'date_cloture' => '2025-10-31',
                    'banque' => 'Crédit Mutuel',
                    'numero_compte' => 'FR76 1234 5678 9012 3456 7890 123',
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');
    $result = app(ReleveOcrService::class)->analyze($file);

    expect($result)->toBeInstanceOf(ReleveOcrResult::class)
        ->and($result->solde_ouverture)->toBe(1000.0)
        ->and($result->solde_cloture)->toBe(1500.50)
        ->and($result->date_cloture)->toBe('2025-10-31')
        ->and($result->banque)->toBe('Crédit Mutuel')
        ->and($result->numero_compte)->toBe('FR76 1234 5678 9012 3456 7890 123')
        ->and($result->warnings)->toBe([]);
});

it('gere les champs manquants', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => null,
                    'solde_cloture' => 1500.50,
                    'date_cloture' => null,
                    'banque' => null,
                    'numero_compte' => null,
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');
    $result = app(ReleveOcrService::class)->analyze($file);

    expect($result->solde_ouverture)->toBeNull()
        ->and($result->solde_cloture)->toBe(1500.50)
        ->and($result->date_cloture)->toBeNull()
        ->and($result->banque)->toBeNull()
        ->and($result->numero_compte)->toBeNull()
        ->and($result->warnings)->toBe([]);
});

it('remonte les warnings', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'solde_ouverture' => 1000.00,
                    'solde_cloture' => 1200.00,
                    'date_cloture' => '2025-10-31',
                    'banque' => 'Banque X',
                    'numero_compte' => null,
                    'warnings' => ['Le document ne ressemble pas à un relevé bancaire.'],
                ]),
            ]],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');
    $result = app(ReleveOcrService::class)->analyze($file);

    expect($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toBe('Le document ne ressemble pas à un relevé bancaire.');
});

it('echoue sur JSON invalide', function (): void {
    $this->association->update(['anthropic_api_key' => 'sk-test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'ceci nest pas du json']],
        ]),
    ]);

    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');
    app(ReleveOcrService::class)->analyze($file);
})->throws(OcrAnalysisException::class);

it('echoue sans cle API', function (): void {
    // Aucune clé API configurée sur l'association : le service doit refuser
    // d'appeler l'API plutôt que de tenter une requête vouée à l'échec.
    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    app(ReleveOcrService::class)->analyze($file);
})->throws(OcrNotConfiguredException::class);
