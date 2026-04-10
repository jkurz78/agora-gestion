<?php

// tests/Feature/ProvisionCompteResultatTest.php

declare(strict_types=1);

use App\Enums\TypeTransaction;
use App\Livewire\RapportCompteResultat;
use App\Models\Categorie;
use App\Models\Provision;
use App\Models\SousCategorie;
use App\Models\User;
use App\Services\ExerciceService;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
    $this->categorie = Categorie::factory()->create();
    $this->sc = SousCategorie::factory()->create(['categorie_id' => $this->categorie->id]);
});

it('displays provisions block in compte de resultat', function () {
    // Exercice courant = current session exercice
    $annee = app(ExerciceService::class)->current();

    Provision::factory()->create([
        'exercice' => $annee,
        'type' => TypeTransaction::Depense,
        'libelle' => 'FNP Maintenance',
        'montant' => 800.00,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'date' => ($annee + 1).'-08-31',
    ]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('PROVISIONS FIN D\'EXERCICE')
        ->assertSee('FNP Maintenance')
        ->assertSee('800,00');
});

it('displays extournes block from previous exercice', function () {
    $annee = app(ExerciceService::class)->current();

    Provision::factory()->create([
        'exercice' => $annee - 1,
        'type' => TypeTransaction::Depense,
        'libelle' => 'FNP Loyer ancien',
        'montant' => 500.00,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'date' => $annee.'-08-31',
    ]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('EXTOURNES PROVISIONS')
        ->assertSee('FNP Loyer ancien');
});

it('computes resultat net including provisions and extournes', function () {
    $annee = app(ExerciceService::class)->current();

    // Provision N: FNP +800 (increases charges, decreases result)
    Provision::factory()->create([
        'exercice' => $annee,
        'type' => TypeTransaction::Depense,
        'montant' => 800.00,
        'sous_categorie_id' => $this->sc->id,
        'saisi_par' => $this->user->id,
        'date' => ($annee + 1).'-08-31',
    ]);

    Livewire::test(RapportCompteResultat::class)
        ->assertSee('AJUSTÉ');
});
