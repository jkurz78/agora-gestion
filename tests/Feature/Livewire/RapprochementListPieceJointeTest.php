<?php

declare(strict_types=1);

use App\Livewire\RapprochementList;
use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
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

    $this->compte = CompteBancaire::factory()->create([
        'association_id' => $this->association->id,
        'est_systeme' => false,
    ]);
    $this->rapprochement = RapprochementBancaire::factory()->create([
        'association_id' => $this->association->id,
        'compte_id' => $this->compte->id,
        'saisi_par' => $this->user->id,
    ]);
});

afterEach(fn () => TenantContext::clear());

it('uploads a piece jointe via the modal', function () {
    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('openPieceJointeModal', $this->rapprochement->id)
        ->assertSet('showPieceJointeModal', true)
        ->set('pieceJointeUpload', $file)
        ->call('uploadPieceJointe')
        ->assertSet('showPieceJointeModal', false);

    $this->rapprochement->refresh();
    expect($this->rapprochement->hasPieceJointe())->toBeTrue();
    expect($this->rapprochement->piece_jointe_nom)->toBe('releve.pdf');
});

it('rejects a non-whitelisted mime', function () {
    $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('openPieceJointeModal', $this->rapprochement->id)
        ->set('pieceJointeUpload', $file)
        ->call('uploadPieceJointe')
        ->assertHasErrors('pieceJointeUpload');

    $this->rapprochement->refresh();
    expect($this->rapprochement->hasPieceJointe())->toBeFalse();
});

it('deletes an existing piece jointe', function () {
    $file = UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf');
    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('openPieceJointeModal', $this->rapprochement->id)
        ->set('pieceJointeUpload', $file)
        ->call('uploadPieceJointe');

    $this->rapprochement->refresh();
    expect($this->rapprochement->hasPieceJointe())->toBeTrue();

    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('deletePieceJointe', $this->rapprochement->id);

    $this->rapprochement->refresh();
    expect($this->rapprochement->hasPieceJointe())->toBeFalse();
});

it('replaces an existing piece jointe', function () {
    $file1 = UploadedFile::fake()->create('old.pdf', 100, 'application/pdf');
    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('openPieceJointeModal', $this->rapprochement->id)
        ->set('pieceJointeUpload', $file1)
        ->call('uploadPieceJointe');

    $this->rapprochement->refresh();
    expect($this->rapprochement->piece_jointe_nom)->toBe('old.pdf');

    $file2 = UploadedFile::fake()->image('new.jpg', 800, 600);
    Livewire::test(RapprochementList::class)
        ->set('compte_id', $this->compte->id)
        ->call('openPieceJointeModal', $this->rapprochement->id)
        ->set('pieceJointeUpload', $file2)
        ->call('uploadPieceJointe');

    $this->rapprochement->refresh();
    expect($this->rapprochement->piece_jointe_nom)->toBe('new.jpg');
    expect($this->rapprochement->piece_jointe_mime)->toBe('image/jpeg');
});
