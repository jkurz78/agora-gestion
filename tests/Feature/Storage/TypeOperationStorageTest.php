<?php

declare(strict_types=1);

use App\Livewire\TypeOperationShow;
use App\Models\Association;
use App\Models\SousCategorie;
use App\Models\TypeOperation;
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
    $this->sousCategorie = SousCategorie::factory()->pourInscriptions()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('upload logo via TypeOperationShow places file under associations/{aid}/type-operations/{tid}/logo.png on local disk', function () {
    $file = UploadedFile::fake()->image('logo.png', 100, 100);
    $aid = $this->association->id;

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Type avec logo')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $type = TypeOperation::where('nom', 'Type avec logo')->first();
    expect($type)->not->toBeNull();
    expect($type->logo_path)->toBe('logo.png');

    $tid = $type->id;
    Storage::disk('local')->assertExists("associations/{$aid}/type-operations/{$tid}/logo.png");
});

it('upload attestation via TypeOperationShow places file under associations/{aid}/type-operations/{tid}/attestation.pdf on local disk', function () {
    $file = UploadedFile::fake()->create('attestation.pdf', 100, 'application/pdf');
    $aid = $this->association->id;

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Type avec attestation')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('attestationMedicale', $file)
        ->call('save')
        ->assertHasNoErrors();

    $type = TypeOperation::where('nom', 'Type avec attestation')->first();
    expect($type)->not->toBeNull();
    expect($type->attestation_medicale_path)->toBe('attestation.pdf');

    $tid = $type->id;
    Storage::disk('local')->assertExists("associations/{$aid}/type-operations/{$tid}/attestation.pdf");
});

it('typeOpLogoFullPath returns correct path when logo_path is set', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'logo_path' => 'logo.png',
    ]);

    $aid = $this->association->id;
    $tid = $type->id;

    expect($type->typeOpLogoFullPath())->toBe("associations/{$aid}/type-operations/{$tid}/logo.png");
});

it('typeOpLogoFullPath returns null when logo_path is null', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'logo_path' => null,
    ]);

    expect($type->typeOpLogoFullPath())->toBeNull();
});

it('typeOpAttestationFullPath returns correct path when attestation_medicale_path is set', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'attestation_medicale_path' => 'attestation.pdf',
    ]);

    $aid = $this->association->id;
    $tid = $type->id;

    expect($type->typeOpAttestationFullPath())->toBe("associations/{$aid}/type-operations/{$tid}/attestation.pdf");
});

it('typeOpAttestationFullPath returns null when attestation_medicale_path is null', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'attestation_medicale_path' => null,
    ]);

    expect($type->typeOpAttestationFullPath())->toBeNull();
});

it('delete logo removes the file from local disk and sets logo_path to null', function () {
    $aid = $this->association->id;

    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $aid,
        'logo_path' => 'logo.png',
    ]);

    $tid = $type->id;
    Storage::disk('local')->put("associations/{$aid}/type-operations/{$tid}/logo.png", 'fake-content');

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->set('nom', $type->nom)
        ->set('sous_categorie_id', $type->sous_categorie_id)
        ->call('save')
        ->assertHasNoErrors();

    // Simulate replacing with a new upload which deletes the old one
    $newFile = UploadedFile::fake()->image('logo.jpg', 100, 100);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type->fresh()])
        ->set('logo', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing("associations/{$aid}/type-operations/{$tid}/logo.png");
    Storage::disk('local')->assertExists("associations/{$aid}/type-operations/{$tid}/logo.jpg");

    expect($type->fresh()->logo_path)->toBe('logo.jpg');
});
