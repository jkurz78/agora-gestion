<?php

declare(strict_types=1);

use App\Livewire\Parametres\AssociationForm;
use App\Models\Association;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates association row with id=1 when none exists', function () {
    $association = Association::find(1) ?? new Association;
    $association->id = 1;
    $association->fill(['nom' => 'Asso Test', 'ville' => 'Paris'])->save();

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)?->nom)->toBe('Asso Test');
});

it('updates existing association without creating duplicate', function () {
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Initial', 'ville' => 'Paris'])->save();

    $assoc2 = Association::find(1) ?? new Association;
    $assoc2->id = 1;
    $assoc2->fill(['nom' => 'Mis à jour', 'ville' => 'Lyon'])->save();

    expect(Association::count())->toBe(1)
        ->and(Association::find(1)?->nom)->toBe('Mis à jour');
});

it('association page is accessible to authenticated user', function () {
    $this->actingAs($this->user)
        ->get(route('compta.parametres.association'))
        ->assertOk();
});

it('association page redirects guest to login', function () {
    $this->get(route('compta.parametres.association'))
        ->assertRedirect(route('login'));
});

it('can save association info via livewire', function () {
    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('adresse', '12 rue des Lilas')
        ->set('code_postal', '75001')
        ->set('ville', 'Paris')
        ->set('email', 'contact@monasso.fr')
        ->set('telephone', '0123456789')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('association', [
        'id' => 1,
        'nom' => 'Mon Asso',
        'email' => 'contact@monasso.fr',
    ]);
});

it('validates nom is required', function () {
    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', '')
        ->call('save')
        ->assertHasErrors(['nom' => 'required']);
});

it('validates email format', function () {
    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('email', 'pas-un-email')
        ->call('save')
        ->assertHasErrors(['email']);
});

it('rejects logo exceeding 2MB', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('logo.png', 3000, 'image/png'); // 3 Mo

    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('rejects logo with invalid mime type', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasErrors(['logo']);
});

it('saves valid logo and persists logo_path', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $file)
        ->call('save')
        ->assertHasNoErrors();

    $association = Association::find(1);
    expect($association->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($association->logo_path);
});

it('deletes old logo file before saving new one', function () {
    Storage::fake('public');

    // Premier upload — use direct assignment (id not in fillable)
    Storage::disk('public')->put('association/logo.png', 'old');
    $assoc = Association::find(1) ?? new Association;
    $assoc->id = 1;
    $assoc->fill(['nom' => 'Mon Asso', 'logo_path' => 'association/logo.png'])->save();

    $newFile = UploadedFile::fake()->image('logo.jpg', 200, 200);

    Livewire::actingAs($this->user)
        ->test(AssociationForm::class)
        ->set('nom', 'Mon Asso')
        ->set('logo', $newFile)
        ->call('save')
        ->assertHasNoErrors();

    Storage::disk('public')->assertMissing('association/logo.png');
});
