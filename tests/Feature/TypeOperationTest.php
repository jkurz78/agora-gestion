<?php

declare(strict_types=1);

use App\Livewire\TypeOperationList;
use App\Livewire\TypeOperationShow;
use App\Models\Association;
use App\Models\Compte;
use App\Models\Operation;
use App\Models\Participant;
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
    // DC-8 : le sélecteur porte des ids de comptes.
    $this->compte = Compte::factory()->numero('706')->pourInscriptions()->create([
        'association_id' => $this->association->id,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('displays the type operations list', function () {
    $type = TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationList::class)
        ->assertOk()
        ->assertSee($type->nom);
});

it('creates a new type operation', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Nouveau type')
        ->set('compte_id', (string) $this->compte->id)
        ->call('save');

    expect(TypeOperation::where('nom', 'Nouveau type')->exists())->toBeTrue();
});

it('creates a type operation with tarifs', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Yoga thérapeutique')
        ->set('compte_id', $this->compte->id)
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
        ->set('compte_id', '')
        ->call('save')
        ->assertHasErrors(['nom', 'compte_id']);
});

it('refuse un compte appartenant à une autre association', function () {
    $autreAssociation = Association::factory()->create();
    TenantContext::boot($autreAssociation);
    $compteExterne = Compte::factory()->numero('706')->pourInscriptions()->create();
    TenantContext::boot($this->association);

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Type avec compte externe')
        ->set('compte_id', (string) $compteExterne->id)
        ->call('save')
        ->assertHasErrors(['compte_id']);

    expect(TypeOperation::where('nom', 'Type avec compte externe')->exists())->toBeFalse();
});

it('edits a type operation', function () {
    $type = TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
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
        'compte_id' => $this->compte->id,
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
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationList::class)
        ->call('delete', $type->id);

    expect(TypeOperation::find($type->id))->toBeNull();
});

it('prevents deletion of tarif used by participants', function () {
    $type = TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
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
    Storage::fake('local');

    $file = UploadedFile::fake()->image('logo.png', 100, 100);

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Test logo')
        ->set('compte_id', $this->compte->id)
        ->set('logo', $file)
        ->call('save');

    $type = TypeOperation::where('nom', 'Test logo')->first();
    expect($type->logo_path)->not->toBeNull();
    $fullPath = $type->typeOpLogoFullPath();
    Storage::disk('local')->assertExists($fullPath);
});

it('filters by active status', function () {
    TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
        'nom' => 'Type actif test',
        'actif' => true,
    ]);
    TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
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
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
        'nom' => 'Nom dupliqué',
    ]);

    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Nom dupliqué')
        ->set('compte_id', $this->compte->id)
        ->call('save')
        ->assertHasErrors(['nom']);
});

it('saves seance titles', function () {
    $type = TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
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
        'compte_id' => $this->compte->id,
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
        'compte_id' => $this->compte->id,
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

it('enregistre la participation optionnelle et son libellé', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Atelier repas')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceActive', true)
        ->set('participationSeanceLibelle', ' Repas ')
        ->call('save')
        ->assertHasNoErrors();

    $type = TypeOperation::where('nom', 'Atelier repas')->sole();
    expect($type->participation_seance_active)->toBeTrue()
        ->and($type->participation_seance_libelle)->toBe('Repas');
});

it('propose « Kiné » comme libellé par défaut', function () {
    Livewire::test(TypeOperationShow::class)
        ->assertSet('participationSeanceLibelle', 'Kiné');

    $type = TypeOperation::factory()->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);
    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->assertSet('participationSeanceActive', false)
        ->assertSet('participationSeanceLibelle', 'Kiné');
});

it('recharge la participation active et son libellé depuis un type existant', function () {
    $type = TypeOperation::factory()->participationSeance('Repas')->create([
        'compte_id' => $this->compte->id,
        'association_id' => $this->association->id,
    ]);

    Livewire::test(TypeOperationShow::class, ['typeOperation' => $type])
        ->assertSet('participationSeanceActive', true)
        ->assertSet('participationSeanceLibelle', 'Repas');
});

it('garde le libellé de participation choisi même quand l\'option est inactive', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Libellé gardé malgré inactif')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceActive', false)
        ->set('participationSeanceLibelle', 'Repas')
        ->call('save')
        ->assertHasNoErrors();

    $type = TypeOperation::where('nom', 'Libellé gardé malgré inactif')->sole();
    expect($type->participation_seance_active)->toBeFalse()
        ->and($type->participation_seance_libelle)->toBe('Repas');
});

it('exige un libellé quand la participation est active et ouvre l\'onglet Séances', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Sans libellé')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceActive', true)
        ->set('participationSeanceLibelle', '   ')
        ->call('save')
        ->assertHasErrors(['participationSeanceLibelle' => 'required'])
        ->assertSet('activeTab', 'seances');

    expect(TypeOperation::where('nom', 'Sans libellé')->exists())->toBeFalse();
});

it('bascule vers l\'onglet Général quand le nom ET le libellé de participation sont en erreur', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', '')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceActive', true)
        ->set('participationSeanceLibelle', '')
        ->call('save')
        ->assertHasErrors(['nom', 'participationSeanceLibelle'])
        ->assertSet('activeTab', 'general');
});

it('n\'exige pas de libellé quand la participation est inactive', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Formation simple')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceLibelle', '')
        ->call('save')
        ->assertHasNoErrors();
});

it('limite le libellé de participation à 12 caractères', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Libellé long')
        ->set('compte_id', (string) $this->compte->id)
        ->set('participationSeanceActive', true)
        ->set('participationSeanceLibelle', str_repeat('a', 13))
        ->call('save')
        ->assertHasErrors(['participationSeanceLibelle' => 'max']);
});

it('range prescripteur, parcours et droit à l\'image dans l\'onglet Général, sans les griser', function () {
    Livewire::test(TypeOperationShow::class)
        ->assertSet('activeTab', 'general')
        ->assertSeeHtml('id="optPrescripteur"')
        ->assertSeeHtml('id="optParcours"')
        ->assertSeeHtml('id="optDroitImage"')
        ->assertDontSeeHtml('x-bind:disabled="!$wire.formulaireActif"')
        ->call('setTab', 'formulaire')
        ->assertDontSeeHtml('id="optParcours"')
        ->assertDontSeeHtml('id="optPrescripteur"')
        ->assertDontSeeHtml('id="optDroitImage"');
});

it('enregistre le parcours thérapeutique sans activer les formulaires', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('nom', 'Parcours sans formulaire')
        ->set('compte_id', (string) $this->compte->id)
        ->set('formulaireActif', false)
        ->set('formulaireParcoursTherapeutique', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(TypeOperation::where('nom', 'Parcours sans formulaire')->sole()->formulaire_parcours_therapeutique)->toBeTrue();
});

it('l\'onglet Formulaire liste les informations suivies et leurs réglages propres', function () {
    Livewire::test(TypeOperationShow::class)
        ->set('formulaireParcoursTherapeutique', true)
        ->call('setTab', 'formulaire')
        ->assertSee('le parcours thérapeutique')
        ->assertSee('Attestation médicale')
        ->assertDontSee('Titre du bloc prescripteur')
        ->assertDontSee('Qualificatif des parcours');

    Livewire::test(TypeOperationShow::class)
        ->call('setTab', 'formulaire')
        ->assertSee("l'onglet Général : aucune", false);

    Livewire::test(TypeOperationShow::class)
        ->set('formulairePrescripteur', true)
        ->set('formulaireDroitImage', true)
        ->call('setTab', 'formulaire')
        ->assertSee('Titre du bloc prescripteur')
        ->assertSee('Qualificatif des parcours');
});

it('l\'onglet Séances porte l\'option de participation et son libellé', function () {
    Livewire::test(TypeOperationShow::class)
        ->call('setTab', 'seances')
        ->assertSee('Collecter une participation optionnelle sur les séances')
        ->assertDontSeeHtml('id="participationSeanceLibelle"')
        ->set('participationSeanceActive', true)
        ->assertSeeHtml('id="participationSeanceLibelle"');
});
