<?php

declare(strict_types=1);

use App\Enums\RoleAssociation;
use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Test Asso'])->save();
});

// ── assignerASeance ───────────────────────────────────────────────────────────

it('consultation ne peut pas assigner à une séance', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertForbidden();
});

it('comptable ne peut pas assigner à une séance', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertForbidden();
});

it('gestionnaire dépasse le guard sur assignerASeance', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);

    // Le guard passe ; la validation échoue faute de données — mais pas 403
    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertHasErrors('selectedSeanceId');
});

// ── assignerAParticipant ──────────────────────────────────────────────────────

it('consultation ne peut pas assigner à un participant', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertForbidden();
});

it('comptable ne peut pas assigner à un participant', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertForbidden();
});

it('gestionnaire dépasse le guard sur assignerAParticipant', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);

    // Le guard passe ; la validation échoue faute de données — mais pas 403
    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertHasErrors('selectedParticipantId');
});

// ── supprimer ─────────────────────────────────────────────────────────────────

it('consultation ne peut pas supprimer un document', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Consultation]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('gestionnaire ne peut pas supprimer un document', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Gestionnaire]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('comptable ne peut pas supprimer un document', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Comptable]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('admin peut supprimer un document', function () {
    $user = User::factory()->create(['role' => RoleAssociation::Admin]);

    $doc = IncomingDocument::create([
        'association_id' => 1,
        'storage_path' => 'incoming-documents/test.pdf',
        'original_filename' => 'test.pdf',
        'sender_email' => 'test@example.com',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', $doc->id)
        ->assertOk();

    expect(IncomingDocument::find($doc->id))->toBeNull();
});
