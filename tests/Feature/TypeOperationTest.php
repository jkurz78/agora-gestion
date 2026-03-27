<?php

declare(strict_types=1);

use App\Livewire\TypeOperationManager;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\TypeOperationTarif;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->sousCategorie = SousCategorie::factory()->pourInscriptions()->create();
});

it('displays the type operations list', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);

    Livewire::test(TypeOperationManager::class)
        ->assertOk()
        ->assertSee($type->nom)
        ->assertSee($type->code);
});

it('creates a type operation with tarifs', function () {
    Livewire::test(TypeOperationManager::class)
        ->call('openCreate')
        ->set('code', 'YOGA')
        ->set('nom', 'Yoga thérapeutique')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('nombre_seances', 10)
        ->set('confidentiel', true)
        ->set('reserve_adherents', false)
        ->set('actif', true)
        ->set('newTarifLibelle', 'Tarif normal')
        ->set('newTarifMontant', '150')
        ->call('addTarif')
        ->set('newTarifLibelle', 'Tarif réduit')
        ->set('newTarifMontant', '100')
        ->call('addTarif')
        ->call('save');

    expect(TypeOperation::where('code', 'YOGA')->exists())->toBeTrue();
    $type = TypeOperation::where('code', 'YOGA')->first();
    expect($type->nom)->toBe('Yoga thérapeutique');
    expect($type->confidentiel)->toBeTrue();
    expect($type->nombre_seances)->toBe(10);
    expect($type->tarifs)->toHaveCount(2);
    expect($type->tarifs->pluck('libelle')->toArray())->toContain('Tarif normal', 'Tarif réduit');
});

it('validates required fields', function () {
    Livewire::test(TypeOperationManager::class)
        ->call('openCreate')
        ->set('code', '')
        ->set('nom', '')
        ->set('sous_categorie_id', '')
        ->call('save')
        ->assertHasErrors(['code', 'nom', 'sous_categorie_id']);
});

it('edits a type operation', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'code' => 'OLD',
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(TypeOperationManager::class)
        ->call('openEdit', $type->id)
        ->set('code', 'NEW')
        ->set('nom', 'Nouveau nom')
        ->call('save');

    $type->refresh();
    expect($type->code)->toBe('NEW');
    expect($type->nom)->toBe('Nouveau nom');
});

it('prevents deletion when operations exist', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);
    Operation::factory()->create(['type_operation_id' => $type->id]);

    Livewire::test(TypeOperationManager::class)
        ->call('delete', $type->id)
        ->assertSet('flashMessage', 'Impossible de supprimer : des opérations utilisent ce type.')
        ->assertSet('flashType', 'danger');

    expect(TypeOperation::find($type->id))->not->toBeNull();
});

it('deletes a type operation without operations', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);

    Livewire::test(TypeOperationManager::class)
        ->call('delete', $type->id);

    expect(TypeOperation::find($type->id))->toBeNull();
});

it('prevents deletion of tarif used by participants', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
    ]);
    $tarif = TypeOperationTarif::factory()->create([
        'type_operation_id' => $type->id,
    ]);

    $operation = Operation::factory()->create(['type_operation_id' => $type->id]);
    Participant::create([
        'tiers_id' => Tiers::factory()->create()->id,
        'operation_id' => $operation->id,
        'type_operation_tarif_id' => $tarif->id,
        'date_inscription' => now(),
    ]);

    // Load the edit form with the tarif, then try to remove it
    Livewire::test(TypeOperationManager::class)
        ->call('openEdit', $type->id)
        ->call('removeTarif', 0)
        ->call('save');

    // The tarif should still exist because it has participants
    expect(TypeOperationTarif::find($tarif->id))->not->toBeNull();
});

it('uploads a logo', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('logo.png', 100, 100);

    Livewire::test(TypeOperationManager::class)
        ->call('openCreate')
        ->set('code', 'LOGO')
        ->set('nom', 'Test logo')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('logo', $file)
        ->call('save');

    $type = TypeOperation::where('code', 'LOGO')->first();
    expect($type->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($type->logo_path);
});

it('filters by active status', function () {
    $actif = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'nom' => 'Type actif test',
        'actif' => true,
    ]);
    $inactif = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'nom' => 'Type inactif test',
        'actif' => false,
    ]);

    // Filter actifs only
    Livewire::test(TypeOperationManager::class)
        ->set('filter', 'actif')
        ->assertSee('Type actif test')
        ->assertDontSee('Type inactif test');

    // Filter inactifs only
    Livewire::test(TypeOperationManager::class)
        ->set('filter', 'inactif')
        ->assertDontSee('Type actif test')
        ->assertSee('Type inactif test');

    // Filter tous
    Livewire::test(TypeOperationManager::class)
        ->set('filter', 'tous')
        ->assertSee('Type actif test')
        ->assertSee('Type inactif test');
});

it('enforces unique code and nom', function () {
    TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'code' => 'DUPL',
        'nom' => 'Nom dupliqué',
    ]);

    Livewire::test(TypeOperationManager::class)
        ->call('openCreate')
        ->set('code', 'DUPL')
        ->set('nom', 'Nom dupliqué')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->call('save')
        ->assertHasErrors(['code', 'nom']);
});
