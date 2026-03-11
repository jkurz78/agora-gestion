<?php

use App\Livewire\Dashboard;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Membre;
use App\Models\Recette;
use App\Models\User;
use App\Services\ExerciceService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->exercice = app(ExerciceService::class)->current();
});

it('renders for authenticated user', function () {
    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee('Solde général')
        ->assertSee('Résumé budget')
        ->assertSee('Dernières dépenses')
        ->assertSee('Dernières recettes')
        ->assertSee('Derniers dons')
        ->assertSee('Membres sans cotisation');
});

it('shows correct exercice', function () {
    $exerciceService = app(ExerciceService::class);
    $label = $exerciceService->label($this->exercice);

    Livewire::test(Dashboard::class)
        ->assertSee($label);
});

it('displays solde general', function () {
    Recette::factory()->create([
        'date' => $this->exercice.'-11-01',
        'montant_total' => 1000.00,
        'saisi_par' => $this->user->id,
    ]);
    Recette::factory()->create([
        'date' => $this->exercice.'-12-01',
        'montant_total' => 500.00,
        'saisi_par' => $this->user->id,
    ]);

    Depense::factory()->create([
        'date' => $this->exercice.'-10-15',
        'montant_total' => 300.00,
        'saisi_par' => $this->user->id,
    ]);

    // Solde = 1000 + 500 - 300 = 1200
    Livewire::test(Dashboard::class)
        ->assertSee('1 200,00');
});

it('shows membres without cotisation', function () {
    $membreWithCotisation = Membre::factory()->create([
        'nom' => 'Durand',
        'prenom' => 'Marie',
    ]);
    Cotisation::factory()->create([
        'membre_id' => $membreWithCotisation->id,
        'exercice' => $this->exercice,
    ]);

    $membreSansCotisation = Membre::factory()->create([
        'nom' => 'Martin',
        'prenom' => 'Pierre',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Martin')
        ->assertSee('Pierre')
        ->assertDontSee('Durand');
});
