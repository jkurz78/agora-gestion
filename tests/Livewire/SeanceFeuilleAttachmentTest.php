<?php

declare(strict_types=1);

use App\Livewire\SeanceFeuilleAttachment;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Seance;
use App\Models\User;
use App\Services\Emargement\Contracts\QrCodeExtractor;
use App\Services\Emargement\QrExtractionResult;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->association = Association::factory()->create();
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);

    $this->gestionnaire = User::factory()->create();
    $this->gestionnaire->associations()->attach($this->association->id, ['role' => 'gestionnaire', 'joined_at' => now()]);

    $this->consultation = User::factory()->create();
    $this->consultation->associations()->attach($this->association->id, ['role' => 'consultation', 'joined_at' => now()]);

    $this->operation = Operation::factory()->create(['association_id' => $this->association->id]);
    $this->seance = Seance::create([
        'operation_id' => $this->operation->id,
        'numero' => 1,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('opens the modal when receiving open-feuille-modal event', function () {
    Livewire::actingAs($this->gestionnaire)
        ->test(SeanceFeuilleAttachment::class)
        ->dispatch('open-feuille-modal', seanceId: $this->seance->id)
        ->assertSet('seanceId', $this->seance->id)
        ->assertSet('show', true);
});

it('uploads and attaches a valid feuille via SeanceFeuilleAttacher', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok($this->seance->id));
    $this->app->instance(QrCodeExtractor::class, $extractor);

    $pdf = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->gestionnaire)
        ->test(SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->seance->id)
        ->set('show', true)
        ->set('feuilleScan', $pdf)
        ->call('envoyer')
        ->assertHasNoErrors()
        ->assertSet('show', false);

    $this->seance->refresh();
    expect($this->seance->feuille_signee_source)->toBe('manual');
});

it('shows a French error when the QR mismatches', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldReceive('extractSeanceIdFromPdf')
        ->once()
        ->andReturn(QrExtractionResult::ok(9999));
    $this->app->instance(QrCodeExtractor::class, $extractor);

    $pdf = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->gestionnaire)
        ->test(SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->seance->id)
        ->set('show', true)
        ->set('feuilleScan', $pdf)
        ->call('envoyer')
        ->assertHasErrors('feuilleScan')
        ->assertSet('show', true);
});

it('allows gestionnaire to retire a feuille', function () {
    Storage::disk('local')->put('emargement/seance-'.$this->seance->id.'.pdf', 'old');
    $this->seance->update([
        'feuille_signee_path' => 'emargement/seance-'.$this->seance->id.'.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    Livewire::actingAs($this->gestionnaire)
        ->test(SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->seance->id)
        ->set('show', true)
        ->call('retirer')
        ->assertSet('show', false);

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBeNull();
    Storage::disk('local')->assertMissing('emargement/seance-'.$this->seance->id.'.pdf');
});

it('forbids consultation user from uploading', function () {
    $extractor = Mockery::mock(QrCodeExtractor::class);
    $extractor->shouldNotReceive('extractSeanceIdFromPdf');
    $this->app->instance(QrCodeExtractor::class, $extractor);

    $pdf = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->consultation)
        ->test(SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->seance->id)
        ->set('show', true)
        ->set('feuilleScan', $pdf)
        ->call('envoyer');

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->toBeNull();
});

it('forbids consultation user from retiring', function () {
    Storage::disk('local')->put('emargement/seance-'.$this->seance->id.'.pdf', 'old');
    $this->seance->update([
        'feuille_signee_path' => 'emargement/seance-'.$this->seance->id.'.pdf',
        'feuille_signee_at' => now(),
        'feuille_signee_source' => 'manual',
    ]);

    Livewire::actingAs($this->consultation)
        ->test(SeanceFeuilleAttachment::class)
        ->set('seanceId', $this->seance->id)
        ->set('show', true)
        ->call('retirer');

    $this->seance->refresh();
    expect($this->seance->feuille_signee_path)->not->toBeNull();
});
