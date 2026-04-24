<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Enums\StatutFactureDeposee;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\FacturePartenaireDeposee;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->create(['anthropic_api_key' => null]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->user = User::factory()->create(['dernier_espace' => Espace::Compta]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

/**
 * Crée un FacturePartenaireDeposee avec un fichier PDF factice sur le disque.
 */
function createDepot(array $attributes = []): FacturePartenaireDeposee
{
    $assocId = TenantContext::currentId();
    $pdfPath = 'associations/'.$assocId.'/factures-partenaires/test-'.uniqid().'.pdf';
    Storage::disk('local')->put($pdfPath, '%PDF-1.4 fake content');

    return FacturePartenaireDeposee::factory()->create(array_merge([
        'association_id' => $assocId,
        'pdf_path' => $pdfPath,
        'statut' => StatutFactureDeposee::Soumise,
        'date_facture' => '2026-04-15',
        'numero_facture' => 'F-2026-0042',
    ], $attributes));
}

it('dispatch open-transaction-form-from-depot-facture pré-remplit le formulaire', function () {
    $depot = createDepot(['date_facture' => '2025-11-20', 'numero_facture' => 'FAC-2025-99']);

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id)
        ->assertSet('showForm', true)
        ->assertSet('type', 'depense')
        ->assertSet('tiers_id', $depot->tiers_id)
        ->assertSet('date', '2025-11-20')
        ->assertSet('reference', 'FAC-2025-99')
        ->assertSet('factureDeposeeId', $depot->id)
        ->assertSet('ocrMode', true)
        ->assertSet('ocrWaitingForFile', false);
});

it('dispatch pré-remplit l\'URL de preview PDF signée', function () {
    $depot = createDepot();

    $component = Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id);

    $url = $component->get('incomingDocumentPreviewUrl');

    expect($url)
        ->toStartWith('http')
        ->toContain('factures-partenaires')
        ->toContain('pdf')
        ->toContain('signature=');
});

it('scope tenant — un depotId d\'un autre tenant est refusé', function () {
    // Créer un dépôt sur une autre association (sans changer le TenantContext courant)
    $autreAssoc = Association::factory()->create();
    $depot = FacturePartenaireDeposee::factory()->create([
        'association_id' => $autreAssoc->id,
        'statut' => StatutFactureDeposee::Soumise,
    ]);

    // Le TenantScope (global scope TenantModel) bloque le find() depuis le tenant courant.
    $test = Livewire::test(TransactionForm::class);
    $test->instance()->openFormFromDepotFacture($depot->id);

    expect($test->instance()->showForm)->toBeFalse();
    expect($test->instance()->factureDeposeeId)->toBeNull();
    expect(session('error'))->toContain('introuvable');
});

it('refuse un dépôt avec statut Traitee', function () {
    $depot = createDepot(['statut' => StatutFactureDeposee::Traitee]);

    $test = Livewire::test(TransactionForm::class);
    $test->instance()->openFormFromDepotFacture($depot->id);

    expect($test->instance()->showForm)->toBeFalse();
    expect($test->instance()->factureDeposeeId)->toBeNull();
    expect(session('error'))->toContain('traitable');
});

it('avec IA configurée — appelle analyzeFromPath avec le contexte tiers/ref/date', function () {
    $this->association->update(['anthropic_api_key' => 'sk-ant-fake']);

    $tiers = Tiers::factory()->pourDepenses()->create(['association_id' => $this->association->id]);
    $depot = createDepot([
        'tiers_id' => $tiers->id,
        'date_facture' => '2026-04-15',
        'numero_facture' => 'F-2026-0042',
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2026-04-15',
                    'reference' => 'F-2026-0042',
                    'tiers_id' => null,
                    'tiers_nom' => $tiers->displayName(),
                    'montant_total' => 150.00,
                    'lignes' => [],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id)
        ->assertSet('showForm', true)
        ->assertSet('reference', 'F-2026-0042')
        ->assertSet('ocrError', null)
        ->assertSet('ocrTiersNom', $tiers->displayName());

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);
        $prompt = collect($body['messages'] ?? [])->pluck('content')->flatten()->implode(' ');

        return str_contains($prompt, 'F-2026-0042')
            && str_contains($prompt, '2026-04-15');
    });
});

it('avec IA non configurée — aucun appel API, formulaire utilisable avec champs pré-remplis', function () {
    // anthropic_api_key = null → isConfigured() false (défaut dans beforeEach)
    Http::fake(); // Aucune requête ne doit partir

    $depot = createDepot(['numero_facture' => 'FAC-NOIA']);

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id)
        ->assertSet('showForm', true)
        ->assertSet('ocrMode', true)
        ->assertSet('ocrError', null)
        ->assertSet('reference', 'FAC-NOIA');

    Http::assertNothingSent();
});

it('avec IA en erreur — ocrError renseigné, formulaire reste utilisable', function () {
    $this->association->update(['anthropic_api_key' => 'sk-ant-fake']);
    $depot = createDepot(['numero_facture' => 'FAC-ERR']);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'Internal Server Error'], 500),
    ]);

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id)
        ->assertSet('showForm', true)
        ->assertSet('reference', 'FAC-ERR')
        ->assertNotSet('ocrError', null); // ocrError est renseigné (non null)
});

it('en mode depot, le bloc upload pieceJointe manuelle n\'est pas rendu', function () {
    $depot = createDepot();

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id)
        ->assertDontSee('Joindre un justificatif');
});

it('hors mode depot, le bloc upload pieceJointe est présent', function () {
    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form', type: 'depense')
        ->assertSee('Joindre un justificatif');
});
