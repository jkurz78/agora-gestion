<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
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
});

afterEach(function () {
    TenantContext::clear();
});

it('upload logo via AssociationForm places file under associations/{id}/branding/ on local disk', function () {
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $association = $this->association->fresh();
    $id = $this->association->id;

    expect($association->logo_path)->toBe('logo.png');
    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.png");
});

it('upload cachet via AssociationForm places file under associations/{id}/branding/ on local disk', function () {
    $file = UploadedFile::fake()->image('cachet.png', 100, 100);

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test Asso')
        ->set('cachet', $file)
        ->call('save')
        ->assertHasNoErrors();

    $association = $this->association->fresh();
    $id = $this->association->id;

    expect($association->cachet_signature_path)->toBe('cachet.png');
    Storage::disk('local')->assertExists("associations/{$id}/branding/cachet.png");
});

it('brandingLogoFullPath returns correct path after upload', function () {
    $id = $this->association->id;
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'dummy');

    $this->association->update(['logo_path' => 'logo.png']);

    expect($this->association->fresh()->brandingLogoFullPath())->toBe("associations/{$id}/branding/logo.png");
});

it('brandingLogoFullPath returns null when logo_path is null', function () {
    $this->association->update(['logo_path' => null]);

    expect($this->association->fresh()->brandingLogoFullPath())->toBeNull();
});

it('brandingCachetFullPath returns correct path after upload', function () {
    $id = $this->association->id;
    Storage::disk('local')->put("associations/{$id}/branding/cachet.png", 'dummy');

    $this->association->update(['cachet_signature_path' => 'cachet.png']);

    expect($this->association->fresh()->brandingCachetFullPath())->toBe("associations/{$id}/branding/cachet.png");
});

it('brandingCachetFullPath returns null when cachet_signature_path is null', function () {
    $this->association->update(['cachet_signature_path' => null]);

    expect($this->association->fresh()->brandingCachetFullPath())->toBeNull();
});

it('uploading a new logo removes the old one from local disk', function () {
    $id = $this->association->id;
    // Seed old logo
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'old-content');
    $this->association->update(['logo_path' => 'logo.png']);

    $newFile = UploadedFile::fake()->image('logo.jpg', 200, 200);

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Test Asso')
        ->set('logo', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing("associations/{$id}/branding/logo.png");
    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.jpg");
});
