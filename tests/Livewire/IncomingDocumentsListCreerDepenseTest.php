<?php

declare(strict_types=1);

use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->association = Association::factory()->create(['anthropic_api_key' => null]);
    TenantContext::boot($this->association);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    $this->actingAs($this->user);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

it('creerDepense dispatche open-transaction-form-from-incoming avec le docId', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::test(IncomingDocumentsList::class)
        ->call('creerDepense', $doc->id)
        ->assertDispatched('open-transaction-form-from-incoming', docId: $doc->id);
});

it('creerDepense échoue sur un docId inexistant', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test']);

    Livewire::test(IncomingDocumentsList::class)
        ->call('creerDepense', 99999);
})->throws(ModelNotFoundException::class);

it('le bouton Créer dépense est visible pour un utilisateur Comptable avec OCR configuré', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $comptable = User::factory()->create();
    $comptable->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    Livewire::actingAs($comptable)
        ->test(IncomingDocumentsList::class)
        ->assertSee('Créer dépense');
});

it('le bouton Créer dépense est invisible pour un utilisateur Gestionnaire', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    Livewire::actingAs($gestionnaire)
        ->test(IncomingDocumentsList::class)
        ->assertDontSee('Créer dépense');
});

it('le bouton Créer dépense est invisible si OCR non configuré', function () {
    // association has no API key

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($this->user)
        ->test(IncomingDocumentsList::class)
        ->assertDontSee('Créer dépense');
});

it('creerDepense abort 403 pour un utilisateur Gestionnaire', function () {
    $this->association->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $gestionnaire = User::factory()->create();
    $gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    Livewire::actingAs($gestionnaire)
        ->test(IncomingDocumentsList::class)
        ->call('creerDepense', $doc->id)
        ->assertStatus(403)
        ->assertNotDispatched('open-transaction-form-from-incoming');
});

it('creerDepense abort 403 si OCR non configuré', function () {
    // association has no API key

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($this->user)
        ->test(IncomingDocumentsList::class)
        ->call('creerDepense', $doc->id)
        ->assertStatus(403)
        ->assertNotDispatched('open-transaction-form-from-incoming');
});
