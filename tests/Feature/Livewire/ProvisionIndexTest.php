<?php

declare(strict_types=1);

use App\Enums\StatutExercice;
use App\Enums\TypeTransaction;
use App\Livewire\Provisions\ProvisionIndex;
use App\Models\Exercice;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = Exercice::create(['annee' => 2025, 'statut' => StatutExercice::Ouvert]);
    session(['exercice_actif' => 2025]);
});

it('renders the provision list', function () {
    $sousCategorie = SousCategorie::factory()->create();

    Provision::factory()->create([
        'exercice' => 2025,
        'libelle' => 'Provision congés payés',
        'type' => TypeTransaction::Depense,
        'montant' => 1200.00,
        'sous_categorie_id' => $sousCategorie->id,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(ProvisionIndex::class)
        ->assertStatus(200)
        ->assertSee('Provision congés payés')
        ->assertSee('2025-2026')
        ->assertSeeHtml('--bs-table-bg:#3d5473');
});

it('creates a provision via modal', function () {
    $sousCategorie = SousCategorie::factory()->create();

    Livewire::test(ProvisionIndex::class)
        ->call('openCreate')
        ->assertSet('showModal', true)
        ->set('libelle', 'Provision loyer')
        ->set('sous_categorie_id', (string) $sousCategorie->id)
        ->set('type', 'depense')
        ->set('montant', '500')
        ->call('save')
        ->assertSet('showModal', false);

    expect(Provision::where('libelle', 'Provision loyer')->count())->toBe(1);

    $provision = Provision::where('libelle', 'Provision loyer')->first();
    expect($provision->exercice)->toBe(2025);
    expect($provision->type)->toBe(TypeTransaction::Depense);
    expect((float) $provision->montant)->toBe(500.0);
    expect($provision->saisi_par)->toBe($this->user->id);
    // date set to end of exercice: 2026-08-31
    expect($provision->date->toDateString())->toBe('2026-08-31');
});

it('edits a provision via modal', function () {
    $sousCategorie = SousCategorie::factory()->create();

    $provision = Provision::factory()->create([
        'exercice' => 2025,
        'libelle' => 'Provision initiale',
        'type' => TypeTransaction::Depense,
        'montant' => 300.00,
        'sous_categorie_id' => $sousCategorie->id,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(ProvisionIndex::class)
        ->call('openEdit', $provision->id)
        ->assertSet('showModal', true)
        ->assertSet('libelle', 'Provision initiale')
        ->assertSet('editingId', $provision->id)
        ->set('libelle', 'Provision modifiée')
        ->set('montant', '750')
        ->call('save')
        ->assertSet('showModal', false);

    $provision->refresh();
    expect($provision->libelle)->toBe('Provision modifiée');
    expect((float) $provision->montant)->toBe(750.0);
});

it('deletes a provision', function () {
    $sousCategorie = SousCategorie::factory()->create();

    $provision = Provision::factory()->create([
        'exercice' => 2025,
        'libelle' => 'À supprimer',
        'sous_categorie_id' => $sousCategorie->id,
        'saisi_par' => $this->user->id,
    ]);

    Livewire::test(ProvisionIndex::class)
        ->call('delete', $provision->id);

    $this->assertSoftDeleted('provisions', ['id' => $provision->id]);
});

it('blocks editing when exercice is closed', function () {
    $this->exercice->update(['statut' => StatutExercice::Cloture]);

    $sousCategorie = SousCategorie::factory()->create();

    Livewire::test(ProvisionIndex::class)
        ->assertSee('clôturé')
        ->call('openCreate')
        ->set('libelle', 'Provision bloquée')
        ->set('sous_categorie_id', (string) $sousCategorie->id)
        ->set('type', 'depense')
        ->set('montant', '100')
        ->call('save')
        ->assertSet('showModal', false)
        ->assertSet('flashType', 'danger');

    expect(Provision::count())->toBe(0);
});

it('validates required fields', function () {
    Livewire::test(ProvisionIndex::class)
        ->call('openCreate')
        ->call('save')
        ->assertHasErrors(['libelle', 'sous_categorie_id', 'type', 'montant']);
});
