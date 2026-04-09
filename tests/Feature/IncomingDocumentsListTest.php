<?php

declare(strict_types=1);

use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    if (Association::find(1) === null) {
        $assoc = new Association;
        $assoc->id = 1;
        $assoc->fill(['nom' => 'Test'])->save();
    }

    $this->user = User::factory()->create();
});

it('lists incoming documents for authenticated user in gestion espace', function () {
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente'))
        ->assertOk()
        ->assertSee('facture.pdf')
        ->assertSee('Non classifié');
});

it('lists incoming documents in compta espace', function () {
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/xyz.pdf',
        'original_filename' => 'scan.pdf',
        'sender_email' => 'copieur@test.fr',
        'received_at' => now(),
        'reason' => 'qr_not_found',
    ]);

    $this->actingAs($this->user)
        ->get(route('compta.documents-en-attente'))
        ->assertOk()
        ->assertSee('scan.pdf')
        ->assertSee('Aucun QR');
});

it('shows empty state when no documents', function () {
    $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente'))
        ->assertOk()
        ->assertSee('Aucun document en attente');
});

it('redirects guest to login', function () {
    $this->get(route('gestion.documents-en-attente'))
        ->assertRedirect(route('login'));
});

it('downloads a document via the controller', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF CONTENT');
    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente.download', $doc));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('facture.pdf');
});

it('returns 404 when downloading a missing file', function () {
    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/missing.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente.download', $doc))
        ->assertNotFound();
});

it('sert la vignette JPEG via la route thumbnail', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    Storage::disk('local')->put('incoming-documents/thumbs/abc.jpg', 'FAKE-JPEG-BYTES');

    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente.thumbnail', $doc))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('renvoie 404 si la vignette n\'existe pas', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');

    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('gestion.documents-en-attente.thumbnail', $doc))
        ->assertNotFound();
});

it('supprime la vignette quand on supprime un document inbox', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    Storage::disk('local')->put('incoming-documents/thumbs/abc.jpg', 'JPEG');

    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($this->user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', $doc->id);

    expect(Storage::disk('local')->exists('incoming-documents/thumbs/abc.jpg'))->toBeFalse()
        ->and(Storage::disk('local')->exists('incoming-documents/abc.pdf'))->toBeFalse();
});
