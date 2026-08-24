<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\Parametres\OcrIaForm;
use App\Models\Association;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Troisième des cinq déplacements qui vident AssociationForm (cf.
 * tests/Feature/Parametres/DecoupeAssociationFormTest.php) : la clé API
 * Anthropic et le modèle OCR quittent l'onglet OCR / IA pour cet écran
 * dédié, dans les services connectés — réservé à l'Admin, comme HelloAsso
 * et le SMTP.
 *
 * Point le plus sensible : `anthropic_api_key` était chargée EN CLAIR dans
 * une propriété publique Livewire sur AssociationForm, donc présente dans le
 * snapshot envoyé au navigateur à chaque rendu — le type="password" ne la
 * masquait que visuellement. Ce composant suit désormais le motif de
 * SmtpForm (`passwordDejaEnregistre`) : le secret n'est jamais chargé, un
 * booléen dit qu'il existe, et un champ laissé vide conserve la valeur en
 * base.
 */
function connecterAvecRolePourOcrIa(Association $association, RoleAssociation $role): User
{
    $user = User::factory()->create();
    $user->associations()->attach($association->id, ['role' => $role->value, 'joined_at' => now()]);

    return $user;
}

it('charge le modèle OCR configuré de l\'association au montage', function (): void {
    $association = Association::factory()->create([
        'invoice_ocr_model' => 'claude-opus-4-8',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->assertSet('invoice_ocr_model', 'claude-opus-4-8');
});

it('enregistre le modèle OCR choisi', function (): void {
    $association = Association::factory()->create(['invoice_ocr_model' => null]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->set('invoice_ocr_model', 'claude-opus-4-8')
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->invoice_ocr_model)->toBe('claude-opus-4-8');
});

/**
 * Le test qui garantit la correction de la faiblesse. Une clé existe en
 * base : on monte le composant et on vérifie trois choses — la propriété
 * publique reste vide (rien à intercepter dans le snapshot Livewire), le
 * booléen signale bien sa présence, et surtout la valeur secrète elle-même
 * n'apparaît nulle part dans le HTML rendu.
 */
it('la clé d\'API n\'est jamais envoyée au client', function (): void {
    $association = Association::factory()->create([
        'anthropic_api_key' => 'sk-ant-super-secrete-1234',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    $composant = Livewire::actingAs($user)->test(OcrIaForm::class)
        ->assertSet('anthropic_api_key', '')
        ->assertSet('cleDejaEnregistree', true);

    expect($composant->html())->not->toContain('sk-ant-super-secrete-1234');
});

/**
 * La garde anti-effacement. Sans elle, ouvrir l'écran et cliquer Enregistrer
 * sans toucher au champ écraserait silencieusement la clé existante — elle
 * est ré-enregistrée à chaque save() puisque save() écrit systématiquement
 * anthropic_api_key.
 */
it('enregistrer sans toucher au champ conserve la clé existante', function (): void {
    $association = Association::factory()->create([
        'anthropic_api_key' => 'sk-ant-cle-existante',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->assertSet('anthropic_api_key', '')
        ->call('save')
        ->assertHasNoErrors();

    $association->refresh();
    expect($association->anthropic_api_key)->toBe('sk-ant-cle-existante');
});

it('saisir une nouvelle clé remplace l\'existante', function (): void {
    $association = Association::factory()->create([
        'anthropic_api_key' => 'sk-ant-cle-ancienne',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->set('anthropic_api_key', 'sk-ant-cle-nouvelle')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('anthropic_api_key', '')
        ->assertSet('cleDejaEnregistree', true);

    $association->refresh();
    expect($association->anthropic_api_key)->toBe('sk-ant-cle-nouvelle');
});

it('un comptable reçoit 403 au montage de OcrIaForm', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Comptable);

    Livewire::actingAs($user)->test(OcrIaForm::class)->assertForbidden();
});

it('chargerModelesOcr peuple le combo depuis GET /v1/models', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'],
                ['id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8'],
            ],
        ]),
    ]);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', [
            'claude-opus-4-8' => 'Claude Opus 4.8',
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
        ])
        ->assertSet('ocrModelsFlashType', 'success');
});

it('chargerModelesOcr se rabat sur la clé en base quand le champ est vide', function (): void {
    $association = Association::factory()->create([
        'anthropic_api_key' => 'sk-ant-cle-en-base',
    ]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'],
            ],
        ]),
    ]);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->assertSet('anthropic_api_key', '')
        ->call('chargerModelesOcr')
        ->assertSet('ocrModelsFlashType', 'success')
        ->assertSet('availableOcrModels', [
            'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
        ]);
});

it('chargerModelesOcr avertit sans clé API du tout', function (): void {
    $association = Association::factory()->create(['anthropic_api_key' => null]);
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->call('chargerModelesOcr')
        ->assertSet('ocrModelsFlashType', 'warning')
        ->assertSet('availableOcrModels', []);
});

it('le modèle déjà choisi reste sélectionnable même retiré de la liste', function (): void {
    $association = Association::factory()->create();
    TenantContext::boot($association);
    $user = connecterAvecRolePourOcrIa($association, RoleAssociation::Admin);

    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response([
            'data' => [['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6']],
        ]),
    ]);

    Livewire::actingAs($user)->test(OcrIaForm::class)
        ->set('anthropic_api_key', 'sk-test-key')
        ->set('invoice_ocr_model', 'claude-vieux-retire')
        ->call('chargerModelesOcr')
        ->assertSet('availableOcrModels', function (array $models): bool {
            return array_key_exists('claude-vieux-retire', $models)
                && array_key_exists('claude-sonnet-4-6', $models);
        });
});
