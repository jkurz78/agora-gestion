<?php

declare(strict_types=1);

// Migré vers OcrIaForm (Task 8 de la découpe d'AssociationForm) : la clé API
// Anthropic et le modèle OCR ont quitté l'onglet OCR / IA pour l'écran dédié
// « OCR / IA », dans les services connectés. Voir
// tests/Feature/Parametres/DecoupeAssociationFormTest.php et
// tests/Livewire/Parametres/OcrIaFormTest.php pour la couverture TDD complète
// du nouveau composant, notamment le motif anti-fuite de la clé API
// (`cleDejaEnregistree`, jamais chargée en clair) — ce fichier est conservé
// pour ne pas perdre son historique de régression, adapté à sa nouvelle
// destination. Le test « ne fait pas perdre l'onglet actif » n'a pas de sens
// ici : OcrIaForm n'a pas d'onglets, contrairement à l'AssociationForm
// d'origine.

use App\Livewire\Parametres\OcrIaForm;
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
    Livewire::test(OcrIaForm::class)
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

    Livewire::test(OcrIaForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', [
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
        ])
        ->assertSet('ocrModelsFlashType', 'success');
});

it('chargerModelesOcr avertit sans clé API', function () {
    Livewire::test(OcrIaForm::class)
        ->set('anthropic_api_key', '')
        ->call('chargerModelesOcr')
        ->assertSet('ocrModelsFlashType', 'warning')
        ->assertSet('availableOcrModels', []);
});

it('save persiste le modèle OCR choisi', function () {
    Livewire::test(OcrIaForm::class)
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

    Livewire::test(OcrIaForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->set('invoice_ocr_model', 'claude-vieux-retire')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', function (array $models): bool {
            return array_key_exists('claude-vieux-retire', $models)
                && array_key_exists('claude-sonnet-4-6', $models);
        });
});
