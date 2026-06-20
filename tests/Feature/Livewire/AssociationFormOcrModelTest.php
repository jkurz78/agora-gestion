<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
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

it('affiche le sélecteur de modèle OCR', function () {
    Livewire::test(AssociationForm::class)
        ->assertSeeHtml('Modèle d\'analyse')
        ->assertSeeHtml('Charger les modèles disponibles');
});

it('chargerModelesOcr peuple le combo depuis GET /v1/models', function () {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'],
                ['id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8'],
            ],
        ]),
    ]);

    Livewire::test(AssociationForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', [
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
        ])
        ->assertSet('ocrModelsFlashType', 'success');
});

it('chargerModelesOcr avertit sans clé API', function () {
    Livewire::test(AssociationForm::class)
        ->set('anthropic_api_key', '')
        ->call('chargerModelesOcr')
        ->assertSet('ocrModelsFlashType', 'warning')
        ->assertSet('availableOcrModels', []);
});

it('save persiste le modèle OCR choisi', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Asso')
        ->set('invoice_ocr_model', 'claude-opus-4-8')
        ->call('save');

    expect($this->association->fresh()->invoice_ocr_model)->toBe('claude-opus-4-8');
});

it('le modèle déjà choisi reste sélectionnable même retiré de la liste', function () {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response([
            'data' => [['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6']],
        ]),
    ]);

    Livewire::test(AssociationForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->set('invoice_ocr_model', 'claude-vieux-retire')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', function (array $models): bool {
            return array_key_exists('claude-vieux-retire', $models)
                && array_key_exists('claude-sonnet-4-6', $models);
        });
});
