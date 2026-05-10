<?php

declare(strict_types=1);

use App\Livewire\Parametres\Adhesions\FormulesList;
use App\Models\FormuleAdhesion;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->user->associations()->attach(TenantContext::currentId(), ['role' => 'admin', 'joined_at' => now()]);
    $this->sc = SousCategorie::factory()->pourCotisations()->create();
});

it('affiche la liste des formules', function (): void {
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $this->sc->id, 'nom' => 'Adhésion adulte']);
    FormuleAdhesion::factory()->create(['sous_categorie_id' => SousCategorie::factory()->pourCotisations(), 'nom' => 'Adhésion étudiant']);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->assertSee('Adhésion adulte')
        ->assertSee('Adhésion étudiant');
});

it('crée une formule mode exercice via la modale', function (): void {
    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->assertSet('showModal', true)
        ->set('nom', 'Nouvelle formule')
        ->set('mode', 'exercice')
        ->set('sousCategorieId', $this->sc->id)
        ->set('montantParDefaut', 30.00)
        ->set('actif', true)
        ->call('save')
        ->assertSet('showModal', false);

    expect(FormuleAdhesion::count())->toBe(1);
    expect(FormuleAdhesion::first()->nom)->toBe('Nouvelle formule');
});

it('crée une formule mode durée avec duree_mois', function (): void {
    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', 'Adhésion 12 mois')
        ->set('mode', 'duree')
        ->set('dureeMois', 12)
        ->set('sousCategorieId', $this->sc->id)
        ->call('save');

    $formule = FormuleAdhesion::first();
    expect($formule->mode)->toBe('duree');
    expect($formule->duree_mois)->toBe(12);
});

it('valide les champs obligatoires', function (): void {
    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', '')
        ->set('mode', 'exercice')
        ->set('sousCategorieId', null)
        ->call('save')
        ->assertHasErrors(['nom', 'sousCategorieId']);
});

it('refuse mode durée sans duree_mois', function (): void {
    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', 'Test')
        ->set('mode', 'duree')
        ->set('dureeMois', null)
        ->set('sousCategorieId', $this->sc->id)
        ->call('save')
        ->assertHasErrors(['dureeMois']);
});

it('refuse une 2e formule active sur la même sous-cat (contrainte applicative remontée à l\'UI)', function (): void {
    FormuleAdhesion::factory()->create(['sous_categorie_id' => $this->sc->id, 'actif' => true]);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', 'Doublon')
        ->set('mode', 'exercice')
        ->set('sousCategorieId', $this->sc->id)
        ->set('actif', true)
        ->call('save')
        ->assertSee('déjà une formule active');
});

it('édite une formule existante via la modale', function (): void {
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $this->sc->id, 'nom' => 'Ancien nom']);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openEdit', $formule->id)
        ->assertSet('showModal', true)
        ->assertSet('nom', 'Ancien nom')
        ->set('nom', 'Nouveau nom')
        ->call('save');

    expect($formule->fresh()->nom)->toBe('Nouveau nom');
});

it('soft-delete une formule', function (): void {
    $formule = FormuleAdhesion::factory()->create(['sous_categorie_id' => $this->sc->id]);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('softDelete', $formule->id);

    expect(FormuleAdhesion::find($formule->id))->toBeNull();
    expect(FormuleAdhesion::withTrashed()->find($formule->id))->not->toBeNull();
});

it('refuse une sous-cat dont l\'usage n\'est pas Cotisation', function (): void {
    $scDon = SousCategorie::factory()->pourDons()->create();

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', 'Test')
        ->set('mode', 'exercice')
        ->set('sousCategorieId', $scDon->id)
        ->call('save')
        ->assertHasErrors(['sousCategorieId']);
});

it('édition d\'une formule HelloAsso : seul le flag actif est modifiable', function (): void {
    $formule = FormuleAdhesion::factory()->create([
        'sous_categorie_id' => $this->sc->id,
        'nom' => 'Adhésion HA',
        'est_helloasso' => true,
        'helloasso_form_slug' => 'cotisation-2025',
        'helloasso_tier_id' => 1,
        'actif' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openEdit', $formule->id)
        ->set('nom', 'Tentative renommage')
        ->set('actif', false)
        ->call('save');

    $formule->refresh();
    expect($formule->nom)->toBe('Adhésion HA'); // PAS modifié
    expect($formule->actif)->toBeFalse(); // modifié
});

it('création d\'une formule mode illimite OK', function (): void {
    // Désactiver toute formule active existante sur la sous-cat pour éviter la contrainte
    FormuleAdhesion::query()->update(['actif' => false]);

    Livewire::actingAs($this->user)
        ->test(FormulesList::class)
        ->call('openCreate')
        ->set('nom', 'Membre à vie')
        ->set('mode', 'illimite')
        ->set('sousCategorieId', $this->sc->id)
        ->set('actif', true)
        ->call('save')
        ->assertSet('showModal', false);

    $formule = FormuleAdhesion::where('nom', 'Membre à vie')->first();
    expect($formule)->not->toBeNull();
    expect($formule->mode)->toBe('illimite');
    expect($formule->duree_mois)->toBeNull();
});
