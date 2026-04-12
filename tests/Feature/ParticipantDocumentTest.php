<?php

declare(strict_types=1);

use App\Models\Operation;
use App\Models\Participant;
use App\Models\Tiers;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->operation = Operation::factory()->create();
    $this->tiers = Tiers::factory()->create();
    $this->participant = Participant::create([
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => '2026-01-15',
    ]);
});

it('downloads document when user has permission', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);

    // Create a fake file
    Storage::disk('local')->put(
        "participants/{$this->participant->id}/certificat.pdf",
        'fake-pdf-content'
    );

    $response = $this->actingAs($user)
        ->get(route('operations.participants.documents.download', [
            'participant' => $this->participant->id,
            'filename' => 'certificat.pdf',
        ]));

    $response->assertOk();
    $response->assertDownload('certificat.pdf');

    // Clean up
    Storage::disk('local')->deleteDirectory("participants/{$this->participant->id}");
});

it('returns 403 when user lacks permission', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => false]);

    Storage::disk('local')->put(
        "participants/{$this->participant->id}/certificat.pdf",
        'fake-pdf-content'
    );

    $response = $this->actingAs($user)
        ->get(route('operations.participants.documents.download', [
            'participant' => $this->participant->id,
            'filename' => 'certificat.pdf',
        ]));

    $response->assertForbidden();

    // Clean up
    Storage::disk('local')->deleteDirectory("participants/{$this->participant->id}");
});

it('returns 404 for missing file', function () {
    $user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);

    $response = $this->actingAs($user)
        ->get(route('operations.participants.documents.download', [
            'participant' => $this->participant->id,
            'filename' => 'nonexistent.pdf',
        ]));

    $response->assertNotFound();
});

it('requires authentication', function () {
    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $this->participant->id,
        'filename' => 'certificat.pdf',
    ]));

    $response->assertRedirect();
});
