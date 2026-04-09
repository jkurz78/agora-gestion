<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Association::firstOrCreate(['id' => 1], ['nom' => 'Test']);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('creerDepense dispatche open-transaction-form-from-incoming avec le docId', function () {
    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    $doc = IncomingDocument::create([
        'association_id' => 1,
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
    Livewire::test(IncomingDocumentsList::class)
        ->call('creerDepense', 99999);
})->throws(ModelNotFoundException::class);

it('le bouton Créer dépense est visible pour un utilisateur Comptable avec OCR configuré', function () {
    $asso = Association::firstOrCreate(['id' => 1], ['nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $comptable = User::factory()->create(['role' => Role::Comptable]);

    Livewire::actingAs($comptable)
        ->test(IncomingDocumentsList::class)
        ->assertSee('Créer dépense');
});

it('le bouton Créer dépense est invisible pour un utilisateur Gestionnaire', function () {
    $asso = Association::firstOrCreate(['id' => 1], ['nom' => 'Test']);
    $asso->update(['anthropic_api_key' => 'sk-test']);

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $gestionnaire = User::factory()->create(['role' => Role::Gestionnaire]);

    Livewire::actingAs($gestionnaire)
        ->test(IncomingDocumentsList::class)
        ->assertDontSee('Créer dépense');
});

it('le bouton Créer dépense est invisible si OCR non configuré', function () {
    Association::firstOrCreate(['id' => 1], ['nom' => 'Test']); // pas de clé API

    Storage::disk('local')->put('incoming-documents/abc.pdf', 'PDF');
    IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/abc.pdf',
        'original_filename' => 'facture.pdf',
        'sender_email' => 'f@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    $admin = User::factory()->create(); // Admin par défaut

    Livewire::actingAs($admin)
        ->test(IncomingDocumentsList::class)
        ->assertDontSee('Créer dépense');
});
