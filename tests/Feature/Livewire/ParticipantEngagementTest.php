<?php

declare(strict_types=1);

use App\Enums\DroitImage;
use App\Livewire\ParticipantEngagementUpload;
use App\Livewire\ParticipantShow;
use App\Models\Association;
use App\Models\FormulaireToken;
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
    $this->association = Association::factory()->create();
    $this->admin = User::factory()->create(['peut_voir_donnees_sensibles' => true]);
    $this->admin->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->admin);

    $this->typeOp = TypeOperation::factory()->create([
        'association_id' => $this->association->id,
        'formulaire_parcours_therapeutique' => true,
        'formulaire_droit_image' => true,
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

it('affiche l\'onglet engagement en mode édition quand formulaire non soumis', function () {
    FormulaireToken::factory()->create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'rempli_at' => null,
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('saisie manuelle');
});

it('affiche l\'onglet engagement en lecture seule quand formulaire soumis', function () {
    FormulaireToken::factory()->create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'rempli_at' => now(),
        'rempli_ip' => '127.0.0.1',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Formulaire soumis le');
});

it('sauvegarde les engagements manuellement', function () {
    FormulaireToken::factory()->create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'rempli_at' => null,
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('editDroitImage', DroitImage::Diffusion->value)
        ->set('editModePaiement', 'comptant')
        ->set('editMoyenPaiement', 'cheque')
        ->set('editAutorisationContactMedecin', true)
        ->call('save');

    $this->participant->refresh();
    expect($this->participant->droit_image)->toBe(DroitImage::Diffusion);
    expect($this->participant->mode_paiement_choisi)->toBe('comptant');
    expect($this->participant->moyen_paiement_choisi)->toBe('cheque');
    expect($this->participant->autorisation_contact_medecin)->toBeTrue();
});

it('ne modifie pas les engagements si formulaire déjà soumis', function () {
    FormulaireToken::factory()->create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'rempli_at' => now(),
        'rempli_ip' => '127.0.0.1',
    ]);

    $this->participant->update(['droit_image' => DroitImage::Refus]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->set('editDroitImage', DroitImage::Diffusion->value)
        ->call('save');

    $this->participant->refresh();
    expect($this->participant->droit_image)->toBe(DroitImage::Refus);
});

it('uploade un document avec label', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf');

    Livewire::test(ParticipantEngagementUpload::class, [
        'participantId' => $this->participant->id,
    ])
        ->set('label', 'Attestation signée')
        ->set('scanFormulaire', $file)
        ->call('enregistrer');

    expect(ParticipantDocument::where('participant_id', $this->participant->id)->count())->toBe(1);

    $doc = ParticipantDocument::where('participant_id', $this->participant->id)->first();
    expect($doc->label)->toBe('Attestation signée');
    expect($doc->source)->toBe('manual-upload');
    // storage_path contient désormais le nom court seulement ; le fichier est sous le chemin tenant-scoped
    expect($doc->storage_path)->not->toContain('/');
    expect(Storage::disk('local')->exists($doc->documentFullPath()))->toBeTrue();
});

it('refuse l\'upload sans label', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->create('scan.pdf', 500, 'application/pdf');

    Livewire::test(ParticipantEngagementUpload::class, [
        'participantId' => $this->participant->id,
    ])
        ->set('label', '')
        ->set('scanFormulaire', $file)
        ->call('enregistrer')
        ->assertHasErrors(['label']);
});

it('affiche le document dans la timeline', function () {
    ParticipantDocument::create([
        'association_id' => $this->association->id,
        'participant_id' => $this->participant->id,
        'label' => 'Formulaire papier',
        'storage_path' => 'test.pdf',
        'original_filename' => 'test.pdf',
        'source' => 'manual-upload',
    ]);

    Livewire::test(ParticipantShow::class, [
        'operation' => $this->operation,
        'participant' => $this->participant,
    ])
        ->assertSee('Document : Formulaire papier');
});
