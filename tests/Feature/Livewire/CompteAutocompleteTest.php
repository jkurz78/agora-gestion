<?php

declare(strict_types=1);

use App\Enums\UsageComptable;
use App\Livewire\CompteAutocomplete;
use App\Models\Association;
use App\Models\Categorie;
use App\Models\Compte;
use App\Models\Famille;
use App\Models\User;
use App\Tenant\TenantContext;
use Livewire\Livewire;

/*
 * DC-10a — composant CompteAutocomplete :
 * recherche/sélection/création directes sur `comptes`.
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
    Livewire::test(CompteAutocomplete::class, ['filtre' => 'recette'])
        ->set('search', '706')
        ->assertSet('open', true)
        ->assertSee('706A')
        ->assertSee('Formations')
        ->assertSee('Ventes et prestations')
        ->assertDontSee('Achats fournitures');
});

it('ne montre que les comptes de la classe demandée par filtre', function () {
    Livewire::test(CompteAutocomplete::class, ['filtre' => 'depense'])
        ->set('search', 'A') // matche à la fois intitulés dépense et recette
        ->assertSee('Achats fournitures')
        ->assertDontSee('Formations');
});

it('sélectionne un compte et affiche son libellé famille/intitulé', function () {
    Livewire::test(CompteAutocomplete::class)
        ->call('selectCompte', $this->compteRecette->id)
        ->assertSet('compteId', $this->compteRecette->id)
        ->assertSet('selectedLabel', 'Formations')
        ->assertSet('selectedFamilleLabel', '70 — Ventes et prestations');
});

it('précharge le compte sélectionné au mount', function () {
    Livewire::test(CompteAutocomplete::class, ['compteId' => $this->compteDepense->id])
        ->assertSet('selectedLabel', 'Achats fournitures')
        ->assertSet('selectedFamilleLabel', '60 — Achats');
});

it('efface la sélection courante', function () {
    Livewire::test(CompteAutocomplete::class)
        ->call('selectCompte', $this->compteRecette->id)
        ->call('clearCompte')
        ->assertSet('compteId', null)
        ->assertSet('selectedLabel', null)
        ->assertSet('selectedFamilleLabel', null);
});

it('filtre par usage comptable porté par le compte', function () {
    $catDon = Categorie::factory()->create(['association_id' => $this->asso->id]);

    $compteDon = Compte::create([
        'association_id' => $this->asso->id,
        'numero_pcg' => '754',
        'intitule' => 'Dons manuels',
        'classe' => 7,
        'categorie_id' => $catDon->id,
        'lettrable' => false,
        'actif' => true,
        'est_systeme' => false,
        'pour_inscriptions' => false,
    ]);
    $compteDon->usages()->create(['association_id' => $this->asso->id, 'usage' => UsageComptable::Don->value]);

    Livewire::test(CompteAutocomplete::class, ['filtre' => 'recette', 'usageFlag' => 'pour_dons'])
        ->set('search', '')
        ->call('doSearch')
        ->assertSee($compteDon->intitule)
        ->assertDontSee($this->compteRecette->intitule);
});

it('crée un compte via la modale (compte-first) puis le sélectionne', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(CompteAutocomplete::class)
        ->call('openCreateModal')
        ->set('newIntitule', 'Cotisations annuelles')

        ->set('newNumeroPcg', '756Z')
        ->call('confirmCreate')
        ->assertSet('showCreateModal', false)
        ->assertSet('selectedLabel', 'Cotisations annuelles');

    $compte = Compte::where('numero_pcg', '756Z')->first();
    expect($compte)->not->toBeNull();
    expect($compte->intitule)->toBe('Cotisations annuelles');
    expect((int) $compte->classe)->toBe(7);
    expect($compte->est_systeme)->toBeFalse();

});

it('rejette la création sans numero de compte (champ obligatoire)', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(CompteAutocomplete::class)
        ->call('openCreateModal')
        ->set('newIntitule', 'Cotisations annuelles')

        ->set('newNumeroPcg', '')
        ->call('confirmCreate')
        ->assertHasErrors(['newNumeroPcg' => 'required']);
});

it('rejette un numéro dont la classe ne correspond pas au filtre', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(CompteAutocomplete::class, ['filtre' => 'recette'])
        ->call('openCreateModal')
        ->set('newIntitule', 'Achat détourné')

        ->set('newNumeroPcg', '606B')
        ->call('confirmCreate')
        ->assertHasErrors(['newNumeroPcg']);

    expect(Compte::where('numero_pcg', '606B')->exists())->toBeFalse();
});

it('réutilise un compte existant sur le même numero_pcg au lieu de planter', function () {
    $cat = Categorie::factory()->create(['association_id' => $this->asso->id]);

    Livewire::test(CompteAutocomplete::class, ['filtre' => 'recette'])
        ->call('openCreateModal')
        ->set('newIntitule', 'Doublon de Formations')

        ->set('newNumeroPcg', '706A')
        ->call('confirmCreate')
        ->assertHasNoErrors()
        ->assertSet('compteId', $this->compteRecette->id)
        ->assertSet('selectedLabel', 'Formations');

    expect(Compte::where('numero_pcg', '706A')->count())->toBe(1);
});
