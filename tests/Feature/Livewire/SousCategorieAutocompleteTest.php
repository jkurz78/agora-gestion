<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Livewire\SousCategorieAutocomplete;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\SousCategorie;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
 * DC-8 item 2 — flip du composant SousCategorieAutocomplete vers Compte/Famille
 * (nom de classe/fichier inchangé, rename reporté à DC-10).
 */
beforeEach(function () {
    $this->asso = Association::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->associations()->attach($this->asso->id, ['role' => 'admin', 'joined_at' => now()]);
    TenantContext::boot($this->asso);
    session(['current_association_id' => $this->asso->id]);
    $this->actingAs($this->admin);

    Famille::factory()->create([
        'association_id' => $this->asso->id,
        'code' => '70',
        'nom' => 'Ventes et prestations',
    ]);
    Famille::factory()->create([
        'association_id' => $this->asso->id,
        'code' => '60',
        'nom' => 'Achats',
    ]);

    $this->compteRecette = Compte::create([
        'association_id' => $this->asso->id,
        'numero_pcg' => '706A',
        'intitule' => 'Formations',
        'classe' => 7,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);

    $this->compteDepense = Compte::create([
        'association_id' => $this->asso->id,
        'numero_pcg' => '606',
        'intitule' => 'Achats fournitures',
        'classe' => 6,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
});

afterEach(function () {
    TenantContext::clear();
});

it('recherche les comptes de recette et les groupe par famille', function () {
    Livewire::test(SousCategorieAutocomplete::class, ['filtre' => 'recette'])
        ->set('search', '706')
        ->assertSet('open', true)
        ->assertSee('706A')
        ->assertSee('Formations')
        ->assertSee('Ventes et prestations')
        ->assertDontSee('Achats fournitures');
});

it('ne montre que les comptes de la classe demandée par filtre', function () {
    Livewire::test(SousCategorieAutocomplete::class, ['filtre' => 'depense'])
        ->set('search', 'A') // matche à la fois intitulés dépense et recette
        ->assertSee('Achats fournitures')
        ->assertDontSee('Formations');
});

it('sélectionne un compte et affiche son libellé famille/intitulé', function () {
    Livewire::test(SousCategorieAutocomplete::class)
        ->call('selectSousCategorie', $this->compteRecette->id)
        ->assertSet('sousCategorieId', $this->compteRecette->id)
        ->assertSet('selectedLabel', 'Formations')
        ->assertSet('selectedFamilleLabel', '70 — Ventes et prestations');
});

it('précharge le compte sélectionné au mount', function () {
    Livewire::test(SousCategorieAutocomplete::class, ['sousCategorieId' => $this->compteDepense->id])
        ->assertSet('selectedLabel', 'Achats fournitures')
        ->assertSet('selectedFamilleLabel', '60 — Achats');
});

it('efface la sélection courante', function () {
    Livewire::test(SousCategorieAutocomplete::class)
        ->call('selectSousCategorie', $this->compteRecette->id)
        ->call('clearSousCategorie')
        ->assertSet('sousCategorieId', null)
        ->assertSet('selectedLabel', null)
        ->assertSet('selectedFamilleLabel', null);
});

it('filtre par usage comptable via le mirroir sous_categorie -> compte', function () {
    $catDon = Categorie::factory()->create(['association_id' => $this->asso->id]);

    $scDon = SousCategorie::factory()->create([
        'association_id' => $this->asso->id,
        'categorie_id' => $catDon->id,
        'nom' => 'Dons manuels',
        'code_cerfa' => '754',
    ]);
    $scDon->usages()->create(['association_id' => $this->asso->id, 'usage' => UsageComptable::Don->value]);

    // Le SousCategorieCompteObserver mirrore automatiquement le compte 754
    // (classe 7) à la création de la sous-catégorie ci-dessus.
    $compteDon = Compte::where('numero_pcg', '754')->firstOrFail();

    Livewire::test(SousCategorieAutocomplete::class, ['filtre' => 'recette', 'sousCategorieFlag' => 'pour_dons'])
        ->set('search', '')
        ->call('doSearch')
        ->assertSee($compteDon->intitule)
        ->assertDontSee($this->compteRecette->intitule);
});

it('crée un compte via la modale (nouveau numero_pcg requis) puis le sélectionne', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(SousCategorieAutocomplete::class)
        ->call('openCreateModal')
        ->set('newNom', 'Cotisations annuelles')
        ->set('newCategorieId', $cat->id)
        ->set('newCodeCerfa', '756Z')
        ->call('confirmCreate')
        ->assertSet('showCreateModal', false)
        ->assertSet('selectedLabel', 'Cotisations annuelles');

    $compte = Compte::where('numero_pcg', '756Z')->first();
    expect($compte)->not->toBeNull();
    expect($compte->intitule)->toBe('Cotisations annuelles');
});

it('rejette la création sans numero de compte (champ desormais obligatoire)', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(SousCategorieAutocomplete::class)
        ->call('openCreateModal')
        ->set('newNom', 'Cotisations annuelles')
        ->set('newCategorieId', $cat->id)
        ->set('newCodeCerfa', '')
        ->call('confirmCreate')
        ->assertHasErrors(['newCodeCerfa' => 'required']);
});
