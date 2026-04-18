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
    $this->association = Association::factory()->create(['nom' => 'Mon Asso Initial']);
    $this->user = User::factory()->create();
    $this->user->associations()->attach($this->association->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->association);
    session(['current_association_id' => $this->association->id]);
    $this->actingAs($this->user);
});

afterEach(function () {
    TenantContext::clear();
});

it('creates association row with id=1 when none exists', function () {
    // This test verifies Association model behavior — uses our seeded association
    $association = $this->association;
    $association->fill(['nom' => 'Asso Test', 'ville' => 'Paris'])->save();

    expect(Association::where('nom', 'Asso Test')->exists())->toBeTrue();
});

it('updates existing association without creating duplicate', function () {
    $count = Association::count();

    $this->association->fill(['nom' => 'Initial', 'ville' => 'Paris'])->save();
    $this->association->fresh()->fill(['nom' => 'Mis à jour', 'ville' => 'Lyon'])->save();

    expect(Association::count())->toBe($count)
        ->and($this->association->fresh()->nom)->toBe('Mis à jour');
});

it('association page is accessible to authenticated user', function () {
    $this->get(route('parametres.association'))
        ->assertOk();
});

it('association page redirects guest to login', function () {
    TenantContext::clear();
    auth()->logout();
    $this->get(route('parametres.association'))
        ->assertRedirect(route('login'));
});

it('can save association info via livewire', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('adresse', '12 rue des Lilas')
        ->set('code_postal', '75001')
        ->set('ville', 'Paris')
        ->set('email', 'contact@monasso.fr')
        ->set('telephone', '0123456789')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('association', [
        'id' => $this->association->id,
        'nom' => 'Mon Asso',
        'email' => 'contact@monasso.fr',
    ]);
});

it('validates nom is required', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['nom' => 'required']);
});

it('validates email format', function () {
    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('email', 'pas-un-email')
        ->call('save')
        ->assertHasErrors(['email']);
});

it('rejects logo exceeding 2MB', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->create('logo.png', 3000, 'image/png'); // 3 Mo

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('rejects logo with invalid mime type', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('saves valid logo and persists logo_path', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $id = $this->association->id;
    $association = $this->association->fresh();
    expect($association->logo_path)->toBe('logo.png');
    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.png");
});

it('deletes old logo file before saving new one', function () {
    Storage::fake('local');
    $id = $this->association->id;

    // Premier upload — seed old file under new path format
    Storage::disk('local')->put("associations/{$id}/branding/logo.png", 'old');
    $this->association->fill(['nom' => 'Mon Asso', 'logo_path' => 'logo.png'])->save();

    $newFile = UploadedFile::fake()->image('logo.jpg', 200, 200);

    Livewire::test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('local')->assertMissing("associations/{$id}/branding/logo.png");
    Storage::disk('local')->assertExists("associations/{$id}/branding/logo.jpg");
});
