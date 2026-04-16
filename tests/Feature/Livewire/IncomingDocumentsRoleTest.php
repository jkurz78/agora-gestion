<?php

declare(strict_types=1);

use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function () {
    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
});

afterEach(function () {
    TenantContext::clear();
});

// ── assignerASeance ───────────────────────────────────────────────────────────

it('consultation ne peut pas assigner à une séance', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertForbidden();
});

it('comptable ne peut pas assigner à une séance', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertForbidden();
});

it('gestionnaire dépasse le guard sur assignerASeance', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    // Le guard passe ; la validation échoue faute de données — mais pas 403
    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerASeance')
        ->assertHasErrors('selectedSeanceId');
});

// ── assignerAParticipant ──────────────────────────────────────────────────────

it('consultation ne peut pas assigner à un participant', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertForbidden();
});

it('comptable ne peut pas assigner à un participant', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertForbidden();
});

it('gestionnaire dépasse le guard sur assignerAParticipant', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    // Le guard passe ; la validation échoue faute de données — mais pas 403
    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('assignerAParticipant')
        ->assertHasErrors('selectedParticipantId');
});

// ── supprimer ─────────────────────────────────────────────────────────────────

it('consultation ne peut pas supprimer un document', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('gestionnaire ne peut pas supprimer un document', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('comptable ne peut pas supprimer un document', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'comptable', 'joined_at' => now()]);

    Livewire::actingAs($user)
        ->test(IncomingDocumentsList::class)
        ->call('supprimer', 1)
        ->assertForbidden();
});

it('admin peut supprimer un document', function () {
    $user = User::factory()->create();
    $user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);

    $doc = IncomingDocument::create([
        'association_id' => $this->association->id,
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
