<?php

declare(strict_types=1);

use App\Livewire\IncomingDocuments\IncomingDocumentsList;
use App\Models\Association;
use App\Models\IncomingDocument;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\QrExtractionResult;
use App\Services\Emargement\SeanceFeuilleAttacher;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);

    $this->operation = Operation::factory()->create();
    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);

    $this->aid = $this->association->id;
    $this->sid = $this->seance->id;
});

afterEach(function () {
    TenantContext::clear();
});

// ── SeanceFeuilleAttacher::attach ─────────────────────────────────────────────

it('SeanceFeuilleAttacher::attach place le fichier sous associations/{aid}/seances/{sid}/feuille-signee.pdf', function () {
    $tempPath = tempnam(sys_get_temp_dir(), 'feuille_');
    file_put_contents($tempPath, '%PDF-1.4 fake');

    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($this->sid));

    $attacher = new SeanceFeuilleAttacher($extractor);
    $result = $attacher->attach($tempPath, 'scan.pdf', $this->seance);

    expect($result->success)->toBeTrue();

    $expectedPath = "associations/{$this->aid}/seances/{$this->sid}/feuille-signee.pdf";
    Storage::disk('local')->assertExists($expectedPath);

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBe('feuille-signee.pdf');

    unlink($tempPath);
});

// ── feuilleSigneeFullPath() accesseur ─────────────────────────────────────────

it('feuilleSigneeFullPath() retourne le chemin tenant-scoped complet', function () {
    $this->seance->update(['feuille_signee_path' => 'feuille-signee.pdf']);
    $this->seance->refresh();

    $expected = "associations/{$this->aid}/seances/{$this->sid}/feuille-signee.pdf";
    expect($this->seance->feuilleSigneeFullPath())->toBe($expected);
});

it('feuilleSigneeFullPath() retourne null quand feuille_signee_path est null', function () {
    expect($this->seance->feuilleSigneeFullPath())->toBeNull();
});

// ── SeanceFeuilleController download & view ───────────────────────────────────

it('download via SeanceFeuilleController sert le bon fichier depuis le chemin tenant-scoped', function () {
    $path = "associations/{$this->aid}/seances/{$this->sid}/feuille-signee.pdf";
    Storage::disk('local')->put($path, 'PDF CONTENT');

    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    $response = $this->get(route('operations.seances.feuille-signee.download', [
        $this->operation, $this->seance,
    ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('feuille-signee-seance-1.pdf');
});

it('download retourne 404 quand feuille_signee_path est null', function () {
    $this->get(route('operations.seances.feuille-signee.download', [
        $this->operation, $this->seance,
    ]))->assertNotFound();
});

// ── Retirer (delete) via SeanceFeuilleAttachment ──────────────────────────────

it('retirer efface le fichier tenant-scoped et met feuille_signee_path à null', function () {
    $path = "associations/{$this->aid}/seances/{$this->sid}/feuille-signee.pdf";
    Storage::disk('local')->put($path, 'old content');

    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    Livewire::actingAs($this->user)
        ->test(\App\Livewire\SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->sid)
        ->set('show', true)
        ->call('retirer')
        ->assertSet('show', false);

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

// ── Routage IncomingDocument → Seance (assignerASeance) ──────────────────────

it('assignerASeance déplace le fichier du chemin incoming vers associations/{aid}/seances/{sid}/feuille-signee.pdf', function () {
    $aid = $this->aid;
    $sid = $this->sid;

    // Simule un IncomingDocument avec un fichier tenant-scoped
    $incomingFilename = 'abc123.pdf';
    $incomingPath = "associations/{$aid}/incoming-documents/{$incomingFilename}";
    Storage::disk('local')->put($incomingPath, 'SCAN PDF');

    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => $incomingFilename,
        'original_filename' => 'scan.pdf',
        'sender_email' => 'upload-manuel',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($this->user)
        ->test(IncomingDocumentsList::class)
        ->set('docIdToAssign', $doc->id)
        ->set('selectedSeanceId', $sid)
        ->call('assignerASeance');

    $expectedPath = "associations/{$aid}/seances/{$sid}/feuille-signee.pdf";
    Storage::disk('local')->assertExists($expectedPath);
    Storage::disk('local')->assertMissing($incomingPath);

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBe('feuille-signee.pdf');
    expect($this->seance->feuille_signee_source)->toBe('manual');

    expect(IncomingDocument::find($doc->id))->toBeNull();
});

it('assignerASeance écrase une feuille existante et efface l\'ancienne', function () {
    $aid = $this->aid;
    $sid = $this->sid;

    // Feuille signée déjà présente
    $existingPath = "associations/{$aid}/seances/{$sid}/feuille-signee.pdf";
    Storage::disk('local')->put($existingPath, 'OLD CONTENT');
    $this->seance->update([
        'feuille_signee_path' => 'feuille-signee.pdf',
        'feuille_signee_at' => now()->subDay(),
        'feuille_signee_source' => 'manual',
    ]);

    // Nouvel IncomingDocument
    $incomingFilename = 'new-scan.pdf';
    Storage::disk('local')->put("associations/{$aid}/incoming-documents/{$incomingFilename}", 'NEW SCAN');
    $doc = IncomingDocument::create([
        'association_id' => $aid,
        'storage_path' => $incomingFilename,
        'original_filename' => 'new-scan.pdf',
        'sender_email' => 'copieur@test.fr',
        'received_at' => now(),
        'reason' => 'unclassified',
    ]);

    Livewire::actingAs($this->user)
        ->test(IncomingDocumentsList::class)
        ->set('docIdToAssign', $doc->id)
        ->set('selectedSeanceId', $sid)
        ->call('assignerASeance');

    Storage::disk('local')->assertExists($existingPath);
    $content = Storage::disk('local')->get($existingPath);
    expect($content)->toBe('NEW SCAN');

    $this->seance->refresh();
    expect($this->seance->feuille_signee_source)->toBe('email');
});
