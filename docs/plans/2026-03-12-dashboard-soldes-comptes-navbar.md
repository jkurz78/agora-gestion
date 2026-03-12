# Dashboard soldes comptes & Refonte navbar — Plan d'implémentation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Afficher le solde courant réel de chaque compte bancaire sur le dashboard, restructurer la Row 1, déplacer le sélecteur d'exercice dans l'en-tête, et réordonner la navbar.

**Architecture:** Nouveau `SoldeService` (pattern identique à `BudgetService::realise()`), modification de `Dashboard.php` + `dashboard.blade.php` pour la nouvelle Row 1, modification de `layouts/app.blade.php` pour la navbar. Une factory `VirementInterneFactory` est créée car elle manque.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP, MySQL via Sail

---

## Task 1 : Factory VirementInterne + SoldeService (TDD)

**Files:**
- Create: `database/factories/VirementInterneFactory.php`
- Create: `app/Services/SoldeService.php`
- Create: `tests/Unit/SoldeServiceTest.php`

### Step 1 : Créer la factory VirementInterne

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompteBancaire;
use App\Models\User;
use App\Models\VirementInterne;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VirementInterne>
 */
class VirementInterneFactory extends Factory
{
    protected $model = VirementInterne::class;

    public function definition(): array
    {
        return [
            'date'                  => fake()->dateTimeBetween('-1 year', 'now'),
            'montant'               => fake()->randomFloat(2, 10, 5000),
            'compte_source_id'      => CompteBancaire::factory(),
            'compte_destination_id' => CompteBancaire::factory(),
            'reference'             => fake()->optional()->numerify('VIR-####'),
            'notes'                 => fake()->optional()->sentence(),
            'saisi_par'             => User::factory(),
        ];
    }
}
```

### Step 2 : Écrire les tests unitaires pour SoldeService

Créer `tests/Unit/SoldeServiceTest.php` :

```php
<?php

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Membre;
use App\Models\Recette;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\SoldeService;

beforeEach(function () {
    $this->service = new SoldeService;
    $this->user = User::factory()->create();
});

it('returns solde_initial when no movements', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    expect($this->service->solde($compte))->toBe(1000.0);
});

it('adds recettes since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 500.00,
        'date_solde_initial' => '2024-06-01',
    ]);
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 200.00,
        'date'          => '2024-07-01',
        'saisi_par'     => $this->user->id,
    ]);
    // Before date_solde_initial — must be ignored
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 999.00,
        'date'          => '2024-05-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(700.0);
});

it('subtracts depenses since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    Depense::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 300.00,
        'date'          => '2024-03-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(700.0);
});

it('adds cotisations (date_paiement) since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 0.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $membre = Membre::factory()->create();
    Cotisation::factory()->create([
        'compte_id'     => $compte->id,
        'montant'       => 50.00,
        'date_paiement' => '2024-02-01',
        'membre_id'     => $membre->id,
    ]);

    expect($this->service->solde($compte))->toBe(50.0);
});

it('subtracts dons since date_solde_initial', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    Don::factory()->create([
        'compte_id' => $compte->id,
        'montant'   => 100.00,
        'date'      => '2024-02-01',
        'saisi_par' => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(900.0);
});

it('adds virements received and subtracts virements sent', function () {
    $source      = CompteBancaire::factory()->create([
        'solde_initial'      => 2000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $destination = CompteBancaire::factory()->create([
        'solde_initial'      => 500.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    VirementInterne::factory()->create([
        'compte_source_id'      => $source->id,
        'compte_destination_id' => $destination->id,
        'montant'               => 400.00,
        'date'                  => '2024-03-01',
        'saisi_par'             => $this->user->id,
    ]);

    expect($this->service->solde($source))->toBe(1600.0);
    expect($this->service->solde($destination))->toBe(900.0);
});

it('ignores soft-deleted depenses', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 1000.00,
        'date_solde_initial' => '2024-01-01',
    ]);
    $depense = Depense::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 300.00,
        'date'          => '2024-03-01',
        'saisi_par'     => $this->user->id,
    ]);
    $depense->delete();

    expect($this->service->solde($compte))->toBe(1000.0);
});

it('handles null date_solde_initial by including all history', function () {
    $compte = CompteBancaire::factory()->create([
        'solde_initial'      => 100.00,
        'date_solde_initial' => null,
    ]);
    Recette::factory()->create([
        'compte_id'     => $compte->id,
        'montant_total' => 50.00,
        'date'          => '2000-01-01',
        'saisi_par'     => $this->user->id,
    ]);

    expect($this->service->solde($compte))->toBe(150.0);
});
```

### Step 3 : Lancer les tests — vérifier qu'ils échouent

```bash
./vendor/bin/sail artisan test tests/Unit/SoldeServiceTest.php
```

Attendu : FAIL — `SoldeService` n'existe pas encore.

### Step 4 : Implémenter SoldeService

Créer `app/Services/SoldeService.php` :

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;

final class SoldeService
{
    public function solde(CompteBancaire $compte): float
    {
        $dateRef = $compte->date_solde_initial?->toDateString() ?? '1900-01-01';

        $entrees =
            (float) $compte->recettes()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) $compte->cotisations()->where('date_paiement', '>=', $dateRef)->sum('montant')
            + (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        $sorties =
            (float) $compte->depenses()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) $compte->dons()->where('date', '>=', $dateRef)->sum('montant')
            + (float) VirementInterne::where('compte_source_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        return round((float) $compte->solde_initial + $entrees - $sorties, 2);
    }
}
```

### Step 5 : Lancer les tests — vérifier qu'ils passent

```bash
./vendor/bin/sail artisan test tests/Unit/SoldeServiceTest.php
```

Attendu : toutes les assertions passent.

### Step 6 : Lancer la suite complète

```bash
./vendor/bin/sail artisan test
```

Attendu : 0 failures (les dépréciations PDO::MYSQL_ATTR_SSL_CA sont pre-existantes, ignorées).

### Step 7 : Commit

```bash
git add database/factories/VirementInterneFactory.php \
        app/Services/SoldeService.php \
        tests/Unit/SoldeServiceTest.php
git commit -m "feat: SoldeService with VirementInterne factory and unit tests"
```

---

## Task 2 : Navbar — réordonner et supprimer Opérations

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

### Step 1 : Remplacer le tableau $navItems

Dans `resources/views/layouts/app.blade.php`, remplacer le bloc `@php $navItems = [...]` par :

```php
$navItems = [
    ['route' => 'dashboard',          'icon' => 'speedometer2',            'label' => 'Tableau de bord'],
    ['route' => 'depenses.index',     'icon' => 'arrow-down-circle',       'label' => 'Dépenses'],
    ['route' => 'recettes.index',     'icon' => 'arrow-up-circle',         'label' => 'Recettes'],
    ['route' => 'virements.index',    'icon' => 'arrow-left-right',        'label' => 'Virements'],
    ['route' => 'budget.index',       'icon' => 'piggy-bank',              'label' => 'Budget'],
    ['route' => 'rapprochement.index','icon' => 'bank',                    'label' => 'Rapprochement'],
    ['route' => 'membres.index',      'icon' => 'people',                  'label' => 'Membres'],
    ['route' => 'dons.index',         'icon' => 'heart',                   'label' => 'Dons'],
    ['route' => 'rapports.index',     'icon' => 'file-earmark-bar-graph',  'label' => 'Rapports'],
    ['route' => 'parametres.index',   'icon' => 'gear',                    'label' => 'Paramètres'],
];
```

Note : `operations.index` est supprimé (Opérations est dans Paramètres > onglet Opérations).

### Step 2 : Vérifier manuellement

Ouvrir http://localhost — vérifier que la navbar affiche les 10 entrées dans le bon ordre et qu'Opérations a disparu.

### Step 3 : Commit

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat: reorder navbar and remove operations entry (moved to parametres)"
```

---

## Task 3 : Dashboard — SoldeService + restructure UI

**Files:**
- Modify: `app/Livewire/Dashboard.php`
- Modify: `resources/views/livewire/dashboard.blade.php`
- Modify: `tests/Livewire/DashboardTest.php`

### Step 1 : Mettre à jour DashboardTest — ajouter le test des soldes

Ajouter à la fin de `tests/Livewire/DashboardTest.php` :

```php
it('displays comptes bancaires with soldes', function () {
    $compte = CompteBancaire::factory()->create([
        'nom'                => 'Compte Principal',
        'solde_initial'      => 1500.00,
        'date_solde_initial' => '2024-01-01',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Compte Principal')
        ->assertSee('1 500,00');
});
```

Ajouter l'import en haut du fichier :
```php
use App\Models\CompteBancaire;
```

### Step 2 : Vérifier que le test échoue

```bash
./vendor/bin/sail artisan test tests/Livewire/DashboardTest.php --filter="displays comptes bancaires"
```

Attendu : FAIL — "Compte Principal" absent de la vue.

### Step 3 : Modifier Dashboard.php

Dans `app/Livewire/Dashboard.php` :

1. Ajouter les imports :
```php
use App\Models\CompteBancaire;
use App\Services\SoldeService;
```

2. Dans `render()`, avant `return view(...)`, ajouter :
```php
$soldeService = app(SoldeService::class);
$comptesAvecSolde = CompteBancaire::orderBy('nom')->get()
    ->map(fn (CompteBancaire $c) => [
        'compte' => $c,
        'solde'  => $soldeService->solde($c),
    ]);
```

3. Ajouter `'comptesAvecSolde' => $comptesAvecSolde,` au tableau passé à la vue.

### Step 4 : Modifier dashboard.blade.php

**4a — En-tête** : Remplacer `<h1 class="mb-4">Tableau de bord</h1>` (la ligne dans `dashboard.blade.php`, pas dans le layout) par :

```html
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Tableau de bord</h1>
    <div style="min-width: 200px;">
        <select wire:model.live="exercice" id="dashboard-exercice" class="form-select">
            @foreach ($exercices as $ex)
                <option value="{{ $ex }}">{{ $exerciceService->label($ex) }}</option>
            @endforeach
        </select>
    </div>
</div>
```

**4b — Row 1** : Remplacer entièrement la `{{-- Row 1: Solde général + Exercice selector --}}` par :

```html
{{-- Row 1: Solde général + Comptes bancaires --}}
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-primary h-100">
            <div class="card-body text-center">
                <h5 class="card-title text-muted mb-1">Solde général</h5>
                <p class="display-5 fw-bold mb-2 {{ $soldeGeneral >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ number_format($soldeGeneral, 2, ',', ' ') }} &euro;
                </p>
                <div class="d-flex justify-content-center gap-4 text-muted small">
                    <span>Recettes : {{ number_format($totalRecettes, 2, ',', ' ') }} &euro;</span>
                    <span>Dépenses : {{ number_format($totalDepenses, 2, ',', ' ') }} &euro;</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bank"></i> Comptes bancaires</h5>
            </div>
            <div class="card-body d-flex align-items-center">
                @if ($comptesAvecSolde->isEmpty())
                    <p class="text-muted mb-0">Aucun compte bancaire configuré.</p>
                @else
                    <div class="row g-2 w-100">
                        @foreach ($comptesAvecSolde as $item)
                            <div class="col">
                                <div class="card text-center border-secondary h-100">
                                    <div class="card-body p-2">
                                        <div class="small text-muted text-truncate">{{ $item['compte']->nom }}</div>
                                        <div class="fw-bold {{ $item['solde'] >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ number_format($item['solde'], 2, ',', ' ') }} &euro;
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
```

### Step 5 : Lancer le test des soldes — vérifier qu'il passe

```bash
./vendor/bin/sail artisan test tests/Livewire/DashboardTest.php
```

Attendu : tous les tests passent.

### Step 6 : Lancer la suite complète

```bash
./vendor/bin/sail artisan test
```

Attendu : 0 failures.

### Step 7 : Vérifier manuellement

Ouvrir http://localhost/dashboard :
- Le sélecteur d'exercice est en haut à droite, aligné avec "Tableau de bord"
- La Row 1 montre le solde général à gauche (plus étroit) et les cartes de comptes à droite
- Changer l'exercice → solde général change, soldes des comptes restent constants (indépendants)

### Step 8 : Commit

```bash
git add app/Livewire/Dashboard.php \
        resources/views/livewire/dashboard.blade.php \
        tests/Livewire/DashboardTest.php
git commit -m "feat: dashboard soldes comptes bancaires and exercice selector in header"
```

---

## Récapitulatif des commits attendus

1. `feat: SoldeService with VirementInterne factory and unit tests`
2. `feat: reorder navbar and remove operations entry (moved to parametres)`
3. `feat: dashboard soldes comptes bancaires and exercice selector in header`
