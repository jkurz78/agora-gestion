<?php

declare(strict_types=1);

use App\Livewire\ParticipantEngagementUpload;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\ParticipantDocument;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'formulaire_parcours_therapeutique' => true,
    ]);
    $this->operation = Operation::factory()->create([
        'association_id' => $this->association->id,
        'type_operation_id' => $this->typeOp->id,
    ]);
    $this->tiers = Tiers::factory()->create(['association_id' => $this->association->id]);
    $this->participant = Participant::create([
        'association_id' => $this->association->id,
        'tiers_id' => $this->tiers->id,
        'operation_id' => $this->operation->id,
        'date_inscription' => today()->toDateString(),
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('upload via ParticipantEngagementUpload stocke le fichier sous associations/{aid}/participants/{pid}/{filename}', function () {
    $file = UploadedFile::fake()->create('formulaire.pdf', 100, 'application/pdf');

    Livewire::test(ParticipantEngagementUpload::class, [
        'participantId' => $this->participant->id,
    ])
        ->set('label', 'Formulaire papier')
        ->set('scanFormulaire', $file)
        ->call('enregistrer');

    $doc = ParticipantDocument::where('participant_id', $this->participant->id)->first();
    expect($doc)->not->toBeNull();

    $aid = $this->association->id;
    $pid = $this->participant->id;
    $expectedDir = "associations/{$aid}/participants/{$pid}";

    // storage_path contient juste le nom court du fichier
    expect($doc->storage_path)->not->toContain('/');

    // Le fichier existe physiquement au bon endroit
    expect(Storage::disk('local')->exists($expectedDir.'/'.$doc->storage_path))->toBeTrue();
});

it('documentFullPath() retourne le chemin complet tenant-scoped', function () {
    $doc = ParticipantDocument::create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'label' => 'Test',
        'storage_path' => 'doc-2026-01-01-120000.pdf',
        'original_filename' => 'test.pdf',
        'source' => 'manual-upload',
    ]);

    $expected = 'associations/'.$this->association->id.'/participants/'.$this->participant->id.'/doc-2026-01-01-120000.pdf';
    expect($doc->documentFullPath())->toBe($expected);
});

it('download via ParticipantDocumentController sert le bon fichier depuis le chemin tenant-scoped', function () {
    $aid = $this->association->id;
    $pid = $this->participant->id;
    $filename = 'certificat.pdf';

    Storage::disk('local')->put(
        "associations/{$aid}/participants/{$pid}/{$filename}",
        'fake-pdf-content'
    );

    $doc = ParticipantDocument::create([
        'association_id' => $aid,
        'participant_id' => $pid,
        'label' => 'Certificat',
        'storage_path' => $filename,
        'original_filename' => $filename,
        'source' => 'manual-upload',
    ]);

    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $pid,
        'filename' => $filename,
    ]));

    $response->assertOk();
    $response->assertDownload($filename);
});

it('download retourne 404 si le fichier est absent du chemin tenant-scoped', function () {
    $response = $this->get(route('operations.participants.documents.download', [
        'participant' => $this->participant->id,
        'filename' => 'inexistant.pdf',
    ]));

    $response->assertNotFound();
});

it('delete du participant (soft-delete) efface le répertoire tenant-scoped', function () {
    $aid = $this->association->id;
    $pid = $this->participant->id;
    $dir = "associations/{$aid}/participants/{$pid}";

    Storage::disk('local')->put("{$dir}/doc.pdf", 'content');
    expect(Storage::disk('local')->exists("{$dir}/doc.pdf"))->toBeTrue();

    $this->participant->delete();

    expect(Storage::disk('local')->exists("{$dir}/doc.pdf"))->toBeFalse();
});
