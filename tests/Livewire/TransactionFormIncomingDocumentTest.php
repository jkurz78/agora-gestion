<?php

declare(strict_types=1);

use App\Enums\Espace;
use App\Livewire\TransactionForm;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\IncomingDocument;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->create(['anthropic_api_key' => 'sk-test']);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
    session(['exercice_actif' => 2025]);

    $this->sousCategorie = SousCategorie::factory()
        ->for(Categorie::factory()->depense()->create(['association_id' => $this->association->id]))
        ->create(['association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
    session()->forget('exercice_actif');
});

function createInboxDocument(string $content = '%PDF-1.4 fake'): IncomingDocument
{
    $associationId = TenantContext::currentId();
    $shortName = 'doc-'.uniqid().'.pdf';
    $fullPath = 'associations/'.$associationId.'/incoming-documents/'.$shortName;
    Storage::disk('local')->put($fullPath, $content);

    return IncomingDocument::create([
        'association_id' => $associationId,
        'storage_path' => $shortName,
        'original_filename' => 'facture-fournisseur.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);
}

it('open-transaction-form-from-incoming charge le document et lance l\'OCR', function () {
    $scId = $this->sousCategorie->id;
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
                        ['description' => 'Élec', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 123.45],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $this->user->update(['dernier_espace' => Espace::Compta]);
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
        ->assertSet('existingPieceJointeNom', 'facture-fournisseur.pdf')
        ->assertSet('incomingDocumentPreviewUrl', route('facturation.documents-en-attente.download', $doc));
});

it('construit l\'URL de prévisu vers la route facturation quel que soit l\'espace', function () {
    $scId = $this->sousCategorie->id;
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => 'FAC-G',
                    'tiers_id' => null,
                    'tiers_nom' => 'EDF',
                    'montant_total' => 50.0,
                    'lignes' => [
                        ['description' => 'X', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 50.0],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $this->user->update(['dernier_espace' => Espace::Gestion]);
    $doc = createInboxDocument();

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id)
        ->assertSet('incomingDocumentPreviewUrl', route('facturation.documents-en-attente.download', $doc));
});

it('open-transaction-form-from-incoming ignore un docId inexistant sans planter', function () {
    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: 99999)
        ->assertSet('showForm', false)
        ->assertSet('incomingDocumentId', null);
});

it('open-transaction-form-from-incoming flash une erreur si le fichier disque manque', function () {
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'ghost.pdf', // n'existe pas sur disque
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

it('save transfère le fichier inbox vers pieces-jointes et supprime l\'IncomingDocument', function () {
    $scId = $this->sousCategorie->id;
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
                        ['description' => 'Élec', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 123.45],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $doc = createInboxDocument('FAKE PDF BYTES');
    $incomingFullPath = $doc->incomingFullPath();

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id)
        ->set('date', '2025-11-22')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    // Transaction créée avec le justificatif
    $tx = Transaction::where('reference', 'FAC-42')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->piece_jointe_nom)->toBe('facture-fournisseur.pdf')
        ->and($tx->piece_jointe_mime)->toBe('application/pdf')
        ->and($tx->piece_jointe_path)->toBe("pieces-jointes/{$tx->id}/justificatif.pdf")
        ->and(Storage::disk('local')->get($tx->piece_jointe_path))->toBe('FAKE PDF BYTES');

    // IncomingDocument supprimé (row + fichier disque)
    expect(IncomingDocument::find($doc->id))->toBeNull()
        ->and(Storage::disk('local')->exists($incomingFullPath))->toBeFalse();
});

it('save conserve l\'IncomingDocument si la validation échoue', function () {
    $scId = $this->sousCategorie->id;
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
                        ['description' => 'Élec', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 123.45],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $doc = createInboxDocument();

    Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id)
        ->set('mode_paiement', '') // forcer l'échec de validation (mode_paiement est required)
        ->call('save')
        ->assertHasErrors('mode_paiement');

    expect(IncomingDocument::find($doc->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists($doc->incomingFullPath()))->toBeTrue();
});

it('openFormFromIncoming flash une erreur pour un utilisateur Gestionnaire (sans droit canEdit)', function () {
    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);
    $doc = createInboxDocument();

    // Cf. test « file missing » plus haut : Livewire::test désactive StartSession dans son
    // sous-request, donc les flashs depuis un dispatch/call sont perdus. On appelle la
    // méthode via instance() pour exécuter dans le contexte Laravel du test parent.
    $this->actingAs($gestionnaire);
    $test = Livewire::test(TransactionForm::class);
    $test->instance()->openFormFromIncoming($doc->id);

    expect($test->instance()->showForm)->toBeFalse();
    expect($test->instance()->incomingDocumentId)->toBeNull();
    expect(session('error'))->toContain('droits');
});

it('save flash un warning et crée la dépense sans justificatif si le fichier inbox disparaît', function () {
    $scId = $this->sousCategorie->id;
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => 'FAC-GHOST',
                    'tiers_id' => null,
                    'tiers_nom' => 'EDF',
                    'montant_total' => 10.00,
                    'lignes' => [
                        ['description' => 'X', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 10.00],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]),
    ]);

    $compte = CompteBancaire::factory()->create(['association_id' => $this->association->id]);
    $doc = createInboxDocument();

    // Simuler la disparition du fichier entre le dispatch et le save
    $component = Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id);

    Storage::disk('local')->delete($doc->incomingFullPath());

    $component
        ->set('date', '2025-11-22')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->call('save')
        ->assertHasNoErrors();

    // La transaction est créée sans justificatif
    $tx = Transaction::where('reference', 'FAC-GHOST')->first();
    expect($tx)->not->toBeNull()
        ->and($tx->piece_jointe_path)->toBeNull();

    // L'IncomingDocument est toujours là (on ne l'a pas supprimée car on n'a pas pu copier)
    expect(IncomingDocument::find($doc->id))->not->toBeNull();
});

it('retryOcr en mode inbox relance analyzeFromPath sur le fichier disque', function () {
    $scId = $this->sousCategorie->id;
    // Première réponse : erreur API. Seconde réponse : succès.
    Http::fakeSequence()
        ->push(['error' => 'boom'], 500)
        ->push([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'date' => '2025-11-22',
                    'reference' => 'FAC-RETRY',
                    'tiers_id' => null,
                    'tiers_nom' => 'EDF',
                    'montant_total' => 50.00,
                    'lignes' => [
                        ['description' => 'Retry', 'sous_categorie_id' => $scId, 'operation_id' => null, 'seance' => null, 'montant' => 50.00],
                    ],
                    'warnings' => [],
                ]),
            ]],
        ]);

    $doc = createInboxDocument();

    $component = Livewire::test(TransactionForm::class)
        ->dispatch('open-transaction-form-from-incoming', docId: $doc->id);

    // Le premier appel a échoué
    expect($component->get('ocrError'))->not->toBeNull();

    $component->call('retryOcr')
        ->assertSet('ocrError', null)
        ->assertSet('reference', 'FAC-RETRY');
});
