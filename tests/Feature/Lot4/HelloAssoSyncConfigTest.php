<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSyncConfig;
use App\Models\CompteBancaire;
use App\Models\HelloAssoParametres;
use App\Models\SousCategorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

    $this->parametres = HelloAssoParametres::create([
        'association_id' => 1,
        'client_id' => 'test',
        'client_secret' => 'secret',
        'organisation_slug' => 'mon-asso',
        'environnement' => 'sandbox',
    ]);
});

it('renders the component', function () {
    Livewire::test(HelloassoSyncConfig::class)
        ->assertStatus(200);
});

it('saves sync config', function () {
    $compte = CompteBancaire::factory()->create(['nom' => 'HA']);
    $scDon = SousCategorie::where('pour_dons', true)->first()
        ?? SousCategorie::factory()->create(['pour_dons' => true]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set('compteHelloassoId', $compte->id)
        ->set('sousCategorieDonId', $scDon->id)
        ->call('sauvegarder')
        ->assertHasNoErrors();

    $this->parametres->refresh();
    expect($this->parametres->compte_helloasso_id)->toBe($compte->id);
    expect($this->parametres->sous_categorie_don_id)->toBe($scDon->id);
});

