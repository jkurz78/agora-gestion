<?php

declare(strict_types=1);

use App\Livewire\TypeOperationList;
use App\Livewire\TypeOperationShow;
use App\Models\Association;
use App\Models\Operation;
use App\Models\Participant;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Models\TypeOperationSeance;
use App\Models\TypeOperationTarif;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
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

it('displays the type operations list', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationList::class)
        ->assertOk()
        ->assertSee($type->nom);
});

it('creates a new type operation', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Nouveau type')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->call('save');

    expect(TypeOperation::where('nom', 'Nouveau type')->exists())->toBeTrue();
});

it('creates a type operation with tarifs', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Yoga thérapeutique')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('nombre_seances', '10')
        ->set('formulaireParcoursTherapeutique', true)
        ->set('formulaireActif', true)
        ->set('reserve_adherents', false)
        ->set('actif', true)
        ->set('newTarifLibelle', 'Tarif normal')
        ->set('newTarifMontant', '150')
        ->call('addTarif')
        ->set('newTarifLibelle', 'Tarif réduit')
        ->set('newTarifMontant', '100')
        ->call('addTarif')
        ->call('save');

    expect(TypeOperation::where('nom', 'Yoga thérapeutique')->exists())->toBeTrue();
    $saved = TypeOperation::where('nom', 'Yoga thérapeutique')->first();
    expect($saved->formulaire_parcours_therapeutique)->toBeTrue();
    expect($saved->formulaire_actif)->toBeTrue();
    expect($saved->nombre_seances)->toBe(10);
    expect($saved->tarifs)->toHaveCount(2);
});

it('validates required fields', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', '')
        ->set('sous_categorie_id', '')
        ->call('save')
        ->assertHasErrors(['nom', 'sous_categorie_id']);
});

it('edits a type operation', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nom' => 'Ancien nom',
    ]);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->set('nom', 'Nouveau nom')
        ->call('save');

    $type->refresh();
    expect($type->nom)->toBe('Nouveau nom');
});

it('prevents deletion when operations exist from list', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
    ]);
    Operation::factory()->create([
        'type_operation_id' => $type->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationList::class)
        ->call('delete', $type->id)
        ->assertSet('flashMessage', 'Impossible de supprimer : des opérations utilisent ce type.')
        ->assertSet('flashType', 'danger');

    expect(TypeOperation::find($type->id))->not->toBeNull();
});

it('deletes a type operation without operations from list', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationList::class)
        ->call('delete', $type->id);

    expect(TypeOperation::find($type->id))->toBeNull();
});

it('prevents deletion of tarif used by participants', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
    ]);
    $tarif = TypeOperationTarif::factory()->create([
        'type_operation_id' => $type->id,
    ]);

    $operation = Operation::factory()->create([
        'type_operation_id' => $type->id,
        'association_id' => $this->association->id,
    ]);
    Participant::create([
        'tiers_id' => Tiers::factory()->create(['association_id' => $this->association->id])->id,
        'operation_id' => $operation->id,
        'type_operation_tarif_id' => $tarif->id,
        'date_inscription' => now(),
    ]);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->call('removeTarif', 0)
        ->call('save');

    expect(TypeOperationTarif::find($tarif->id))->not->toBeNull();
});

it('uploads a logo', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->image('logo.png', 100, 100);

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Test logo')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->set('logo', $file)
        ->call('save');

    $type = TypeOperation::where('nom', 'Test logo')->first();
    expect($type->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($type->logo_path);
});

it('filters by active status', function () {
    TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nom' => 'Type actif test',
        'actif' => true,
    ]);
    TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nom' => 'Type inactif test',
        'actif' => false,
    ]);

    Livewire::test(TypeOperationList::class)
        ->set('filter', 'actif')
        ->assertSee('Type actif test')
        ->assertDontSee('Type inactif test');

    Livewire::test(TypeOperationList::class)
        ->set('filter', 'inactif')
        ->assertDontSee('Type actif test')
        ->assertSee('Type inactif test');

    Livewire::test(TypeOperationList::class)
        ->set('filter', 'tous')
        ->assertSee('Type actif test')
        ->assertSee('Type inactif test');
});

it('enforces unique nom', function () {
    TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nom' => 'Nom dupliqué',
    ]);

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Nom dupliqué')
        ->set('sous_categorie_id', $this->sousCategorie->id)
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('saves seance titles', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nombre_seances' => 3,
    ]);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->set('seanceTitres.0.titre', 'Les bases')
        ->set('seanceTitres.1.titre', 'Approfondissement')
        ->set('seanceTitres.2.titre', 'Synthèse')
        ->call('save');

    $seances = TypeOperationSeance::where('type_operation_id', $type->id)
        ->orderBy('numero')
        ->get();

    expect($seances)->toHaveCount(3);
    expect($seances[0]->titre)->toBe('Les bases');
    expect($seances[1]->titre)->toBe('Approfondissement');
    expect($seances[2]->titre)->toBe('Synthèse');
});

it('adjusts seance titles when nombre_seances changes', function () {
    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
        'nombre_seances' => 2,
    ]);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->assertCount('seanceTitres', 2)
        ->set('nombre_seances', '4')
        ->assertCount('seanceTitres', 4);
});

it('routes to the new type-operation pages', function () {
    $response = $this->get('/operations/types-operation');
    $response->assertOk();

    $type = TypeOperation::factory()->create([
        'sous_categorie_id' => $this->sousCategorie->id,
        'association_id' => $this->association->id,
    ]);

    $response = $this->get("/operations/types-operation/{$type->id}");
    $response->assertOk();

    $response = $this->get('/operations/types-operation/create');
    $response->assertOk();
});

it('redirects old URLs to new ones', function () {
    $response = $this->get('/compta/parametres/type-operations');
    $response->assertRedirect('/operations/types-operation');
    $response->assertStatus(301);
});
