<?php

declare(strict_types=1);

use App\Livewire\Parametres\HelloassoSyncConfig;
use App\Models\CompteBancaire;
use App\Models\HelloAssoFormMapping;
use App\Models\HelloAssoParametres;
use App\Models\Operation;
use App\Models\SousCategorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    \DB::table('association')->insertOrIgnore(['id' => 1, 'nom' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

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

it('loads forms from API and syncs mappings', function () {
    Http::fake([
        '*/oauth2/token' => Http::response(['access_token' => 'fake-token'], 200),
        '*/v5/organizations/mon-asso/forms*' => Http::sequence()
            ->push([
                'data' => [
                    ['formSlug' => 'adhesion-2025', 'formType' => 'Membership', 'title' => 'Adhésion 2025', 'state' => 'Public'],
                    ['formSlug' => 'dons-libres', 'formType' => 'Donation', 'title' => 'Dons libres', 'state' => 'Public'],
                ],
                'pagination' => [],
            ])
            ->push(['data' => [], 'pagination' => []]),
    ]);

    Livewire::test(HelloassoSyncConfig::class)
        ->call('chargerFormulaires')
        ->assertSee('adhesion-2025')
        ->assertSee('dons-libres');

    expect(HelloAssoFormMapping::count())->toBe(2);
});

it('saves form-to-operation mapping', function () {
    $operation = Operation::factory()->create(['nom' => 'Stage']);
    $mapping = HelloAssoFormMapping::create([
        'helloasso_parametres_id' => $this->parametres->id,
        'form_slug' => 'stage-ete',
        'form_type' => 'Event',
        'form_title' => 'Stage été',
    ]);

    Livewire::test(HelloassoSyncConfig::class)
        ->set("formOperations.{$mapping->id}", $operation->id)
        ->call('sauvegarderFormulaires')
        ->assertHasNoErrors();

    $mapping->refresh();
    expect($mapping->operation_id)->toBe($operation->id);
});
