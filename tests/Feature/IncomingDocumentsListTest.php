<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('lists incoming documents for authenticated user in gestion espace', function () {
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('facturation.documents-en-attente'))
        ->assertOk()
        ->assertSee('facture.pdf')
        ->assertSee('Non classifié');
});

it('lists incoming documents in compta espace', function () {
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/xyz.pdf',
        'original_filename' => 'scan.pdf',
        'sender_email' => 'copieur@test.fr',
        'received_at' => now(),
        'reason' => 'qr_not_found',
    ]);

    $this->actingAs($this->user)
        ->get(route('facturation.documents-en-attente'))
        ->assertOk()
        ->assertSee('scan.pdf')
        ->assertSee('Aucun QR');
});

it('shows empty state when no documents', function () {
    $this->actingAs($this->user)
        ->get(route('facturation.documents-en-attente'))
        ->assertOk()
        ->assertSee('Aucun document en attente');
});

it('redirects guest to login', function () {
    $this->get(route('facturation.documents-en-attente'))
        ->assertRedirect(route('login'));
});

it('downloads a document via the controller', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF CONTENT');
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('facturation.documents-en-attente.download', $doc));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('facture.pdf');
});

it('returns 404 when downloading a missing file', function () {
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/missing.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'fournisseur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $this->actingAs($this->user)
        ->get(route('facturation.documents-en-attente.download', $doc))
        ->assertNotFound();
});
