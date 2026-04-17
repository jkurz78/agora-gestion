<?php

declare(strict_types=1);

use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('downloads document when user has permission', function () {
    // Create a fake file
    Storage::disk('local')->put(
        "participants/{$this->participant->id}/certificat.pdf",
        'fake-pdf-content'
    );

    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $this->participant->id,
        'filename' => 'certificat.pdf',
    ]));

    $response->assertOk();
    $response->assertDownload('certificat.pdf');

    // Clean up
    Storage::disk('local')->deleteDirectory("participants/{$this->participant->id}");
});

it('returns 403 when user lacks permission', function () {
    $userLimited = User::factory()->create(['peut_voir_donnees_sensibles' => false]);
    $userLimited->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    Storage::disk('local')->put(
        "participants/{$this->participant->id}/certificat.pdf",
        'fake-pdf-content'
    );

    $response = $this->actingAs($userLimited)
        ->get(route('operations.participants.documents.download', [
            'participant' => $this->participant->id,
            'filename' => 'certificat.pdf',
        ]));

    $response->assertForbidden();

    // Clean up
    Storage::disk('local')->deleteDirectory("participants/{$this->participant->id}");
});

it('returns 404 for missing file', function () {
    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $this->participant->id,
        'filename' => 'nonexistent.pdf',
    ]));

    $response->assertNotFound();
});

it('requires authentication', function () {
    TenantContext::clear();
    auth()->logout();
    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $this->participant->id,
        'filename' => 'certificat.pdf',
    ]));

    $response->assertRedirect();
});
