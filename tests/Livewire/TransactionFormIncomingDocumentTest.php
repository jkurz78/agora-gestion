<?php

declare(strict_types=1);

use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\SousCategorie;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $asso = Association::firstOrCreate(['id' => 1], ['nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test']);

    SousCategorie::factory()->create();
});

function createInboxDocument(string $content = '%PDF-1.4 fake'): IncomingDocument
{
    $path = 'incoming-documents/doc-'.uniqid().'.pdf';
    Storage::disk('local')->put($path, $content);

    return IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => $path,
        'original_filename' => 'facture-fournisseur.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);
}

it('open-transaction-form-from-incoming charge le document et lance l\'OCR', function () {
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
                        ['description' => 'Élec', 'sous_categorie_id' => 1, 'operation_id' => null, 'seance' => null, 'montant' => 123.45],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $doc = createInboxDocument();

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id)
        ->assertSet('showForm', true)
        ->assertSet('type', 'depense')
        ->assertSet('ocrMode', true)
        ->assertSet('ocrWaitingForFile', false)
        ->assertSet('incomingDocumentId', $doc->id)
        ->assertSet('reference', 'FAC-42')
        ->assertSet('ocrTiersNom', 'EDF')
        ->assertSet('existingPieceJointeNom', 'facture-fournisseur.pdf');
});

it('open-transaction-form-from-incoming ignore un docId inexistant sans planter', function () {
    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: 99999)
        ->assertSet('showForm', false)
        ->assertSet('incomingDocumentId', null);
});

it('open-transaction-form-from-incoming flash une erreur si le fichier disque manque', function () {
    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/ghost.pdf', // n'existe pas sur disque
        'original_filename' => 'ghost.pdf',
        'sender_email' => 'test@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    // Note : on passe par instance() plutôt que dispatch() car Livewire::test désactive
    // le middleware StartSession dans son sous-request, donc les flashs depuis l'intérieur
    // du composant sont perdus. Appeler la méthode sur l'instance exécute dans le contexte
    // Laravel du test parent où la session persiste.
    $test = Livewire::test(TransactionForm::class);
    $test->instance()->openFormFromIncoming($doc->id);

    expect($test->instance()->showForm)->toBeFalse();
    expect(session('error'))->toBe('Fichier introuvable sur le disque.');
});
