# Validation des dates par exercice — Plan d'implémentation

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empêcher la saisie de transactions hors de l'exercice actif, avec une date par défaut intelligente.

**Architecture:** Ajouter `defaultDate()` à `ExerciceService` pour centraliser la logique de date par défaut. Chaque formulaire Livewire ajoute les règles `after_or_equal` / `before_or_equal` dans sa validation, et utilise `defaultDate()` pour pré-remplir la date. Le rapprochement bancaire n'est PAS modifié — son filtre par `rapprochement_id` est déjà correct. Les tests Livewire vont dans `tests/Livewire/`, pas `tests/Feature/`.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, CarbonImmutable

---

## Fichiers touchés

| Fichier | Action |
|---|---|
| `app/Services/ExerciceService.php` | Ajouter `defaultDate(): string` |
| `app/Livewire/DepenseForm.php` | Validation date + date par défaut dans `showNewForm()` |
| `app/Livewire/RecetteForm.php` | Validation date + date par défaut dans `showNewForm()` |
| `app/Livewire/DonForm.php` | Ajouter `showNewForm()` avec date par défaut + validation |
| `resources/views/livewire/don-form.blade.php` | Remplacer `$set('showForm', true)` par `showNewForm()` |
| `app/Livewire/VirementInterneForm.php` | Validation date + date par défaut dans `showNewForm()` |
| `app/Livewire/CotisationForm.php` | Validation date_paiement + date par défaut dans `mount()` ET après reset dans `save()` |
| `tests/Feature/Services/ExerciceServiceTest.php` | Tests defaultDate() |
| `tests/Livewire/DepenseFormTest.php` | Tests validation date hors exercice |
| `tests/Livewire/RecetteFormTest.php` | Tests validation date hors exercice |
| `tests/Livewire/DonFormTest.php` | Tests validation date hors exercice |
| `tests/Livewire/CotisationFormTest.php` | Tests validation date_paiement hors exercice |

---

## Chunk 1 : ExerciceService — méthode defaultDate()

### Task 1 : Ajouter `defaultDate()` à ExerciceService

**Files:**
- Modify: `app/Services/ExerciceService.php`
- Test: `tests/Feature/Services/ExerciceServiceTest.php`

**Contexte:** `dateRange()` retourne `start` et `end` en `CarbonImmutable`. `defaultDate()` retourne aujourd'hui si dans l'exercice, la date de fin si l'exercice est passé, la date de début si l'exercice est futur.

- [ ] **Step 1 : Écrire les tests**

```php
<?php
// tests/Feature/Services/ExerciceServiceTest.php

use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow(null);
    session()->forget('exercice_actif');
});

it('defaultDate retourne aujourd\'hui si dans l\'exercice', function () {
    CarbonImmutable::setTestNow('2025-10-15');
    session(['exercice_actif' => 2025]); // 2025-09-01 → 2026-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2025-10-15');
});

it('defaultDate retourne dateFin si aujourd\'hui est après l\'exercice', function () {
    CarbonImmutable::setTestNow('2026-03-14');
    session(['exercice_actif' => 2023]); // 2023-09-01 → 2024-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2024-08-31');
});

it('defaultDate retourne dateDebut si aujourd\'hui est avant l\'exercice', function () {
    CarbonImmutable::setTestNow('2026-03-14');
    session(['exercice_actif' => 2027]); // 2027-09-01 → 2028-08-31

    $result = app(ExerciceService::class)->defaultDate();

    expect($result)->toBe('2027-09-01');
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/ExerciceServiceTest.php
```
Attendu : FAIL — méthode inexistante

- [ ] **Step 3 : Implémenter `defaultDate()`**

Dans `app/Services/ExerciceService.php`, ajouter après `label()` :

```php
/**
 * Return the best default date for a new entry in the active exercice.
 * Returns today if in range, dateFin if past, dateDebut if future.
 */
public function defaultDate(): string
{
    $range = $this->dateRange($this->current());
    $today = CarbonImmutable::today();

    if ($today->lt($range['start'])) {
        return $range['start']->toDateString();
    }

    if ($today->gt($range['end'])) {
        return $range['end']->toDateString();
    }

    return $today->toDateString();
}
```

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Services/ExerciceServiceTest.php
```
Attendu : 3 tests PASS

- [ ] **Step 5 : Commit**

```bash
git add app/Services/ExerciceService.php tests/Feature/Services/ExerciceServiceTest.php
git commit -m "feat: ExerciceService::defaultDate() — date par défaut intelligente selon exercice"
```

---

## Chunk 2 : DepenseForm

### Task 2 : Borner la date dans DepenseForm

**Files:**
- Modify: `app/Livewire/DepenseForm.php`
- Test: `tests/Livewire/DepenseFormTest.php`

**Contexte :** La date est initialisée dans `showNewForm()` (ligne 56 : `$this->date = now()->format('Y-m-d')`), pas dans `resetForm()`. La validation est dans `save()` (ligne 136 : `'date' => ['required', 'date']`). Les tests Livewire de Depense utilisent un tableau `lignes` — ne pas utiliser `montant_total` (c'est une propriété calculée, pas writable).

- [ ] **Step 1 : Écrire les tests**

Ajouter à la fin de `tests/Livewire/DepenseFormTest.php` :

```php
it('rejette une date avant le début de l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]); // 2025-09-01 → 2026-08-31
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-31') // avant 2025-09-01
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('rejette une date après la fin de l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2026-09-01') // après 2026-08-31
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasErrors(['date']);
});

it('accepte une date dans l\'exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]);
    $compte = \App\Models\CompteBancaire::factory()->create();
    $cat = \App\Models\Categorie::factory()->create(['type' => \App\Enums\TypeCategorie::Depense]);
    $sc  = \App\Models\SousCategorie::factory()->create(['categorie_id' => $cat->id]);

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DepenseForm::class)
        ->call('showNewForm')
        ->set('date', '2025-10-01')
        ->set('libelle', 'Test')
        ->set('mode_paiement', 'virement')
        ->set('compte_id', $compte->id)
        ->set('lignes', [['sous_categorie_id' => $sc->id, 'operation_id' => '', 'seance' => '', 'montant' => '100.00', 'notes' => '']])
        ->call('save')
        ->assertHasNoErrors(['date']);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseFormTest.php
```
Attendu : 2 premiers nouveaux tests FAIL

- [ ] **Step 3 : Modifier DepenseForm**

Ajouter l'import (si absent) :
```php
use App\Services\ExerciceService;
```

Dans `showNewForm()`, remplacer :
```php
$this->date = now()->format('Y-m-d');
```
par :
```php
$this->date = app(ExerciceService::class)->defaultDate();
```

Dans `save()`, avant `$this->validate([...])`, ajouter :
```php
$exerciceService = app(ExerciceService::class);
$range     = $exerciceService->dateRange($exerciceService->current());
$dateDebut = $range['start']->toDateString();
$dateFin   = $range['end']->toDateString();
```

Remplacer la règle date :
```php
'date' => ['required', 'date', 'after_or_equal:'.$dateDebut, 'before_or_equal:'.$dateFin],
```

Passer les messages en 2e argument de `validate()` :
```php
$this->validate(
    [/* toutes les règles */],
    [
        'date.after_or_equal'  => 'La date doit être dans l\'exercice en cours (à partir du '.date('d/m/Y', strtotime($dateDebut)).').',
        'date.before_or_equal' => 'La date doit être dans l\'exercice en cours (jusqu\'au '.date('d/m/Y', strtotime($dateFin)).').',
    ]
);
```

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Livewire/DepenseFormTest.php
```
Attendu : tous PASS

- [ ] **Step 5 : Commit**

```bash
git add app/Livewire/DepenseForm.php tests/Livewire/DepenseFormTest.php
git commit -m "feat: DepenseForm — date bornée à l'exercice actif + date par défaut intelligente"
```

---

## Chunk 3 : RecetteForm, DonForm, VirementInterneForm, CotisationForm

### Task 3 : RecetteForm

**Files:**
- Modify: `app/Livewire/RecetteForm.php`
- Test: `tests/Livewire/RecetteFormTest.php`

Même structure exacte que Task 2 (RecetteForm est identique à DepenseForm). La date est aussi dans `showNewForm()`.

- [ ] **Step 1 : Ajouter les tests** dans `tests/Livewire/RecetteFormTest.php` (même structure que DepenseForm, adapter les noms de composant et le type de catégorie : `TypeCategorie::Recette`)

- [ ] **Step 2 : Lancer pour vérifier l'échec**
```bash
./vendor/bin/sail artisan test tests/Livewire/RecetteFormTest.php
```

- [ ] **Step 3 : Modifier RecetteForm** — mêmes modifications que DepenseForm :
  - Ajouter import `ExerciceService`
  - `showNewForm()` : `$this->date = app(ExerciceService::class)->defaultDate()`
  - `save()` : calcul `$dateDebut`/`$dateFin` + règles + messages

- [ ] **Step 4 : Lancer les tests**
```bash
./vendor/bin/sail artisan test tests/Livewire/RecetteFormTest.php
```
Attendu : tous PASS

- [ ] **Step 5 : Commit**
```bash
git add app/Livewire/RecetteForm.php tests/Livewire/RecetteFormTest.php
git commit -m "feat: RecetteForm — date bornée à l'exercice actif + date par défaut intelligente"
```

---

### Task 4 : DonForm

**Files:**
- Modify: `app/Livewire/DonForm.php`
- Modify: `resources/views/livewire/don-form.blade.php`
- Test: `tests/Livewire/DonFormTest.php`

**Contexte :** DonForm n'a pas de `showNewForm()` — le bouton dans la vue fait `wire:click="$set('showForm', true)"` directement. Il faut ajouter une méthode `showNewForm()` qui initialise la date, et mettre à jour la vue en conséquence.

- [ ] **Step 1 : Ajouter les tests** dans `tests/Livewire/DonFormTest.php` :

```php
it('rejette une date hors exercice', function () {
    $user = \App\Models\User::factory()->create();
    session(['exercice_actif' => 2025]);
    $donateur = \App\Models\Donateur::factory()->create();

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\DonForm::class)
        ->call('showNewForm')
        ->set('date', '2025-08-01')
        ->set('montant', '50')
        ->set('mode_paiement', 'cheque')
        ->set('donateur_id', $donateur->id)
        ->call('save')
        ->assertHasErrors(['date']);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**
```bash
./vendor/bin/sail artisan test tests/Livewire/DonFormTest.php --filter="hors exercice"
```

- [ ] **Step 3 : Ajouter `showNewForm()` dans DonForm**

Ajouter import `ExerciceService`.

Ajouter la méthode après les propriétés publiques :
```php
public function showNewForm(): void
{
    $this->resetForm();
    $this->date     = app(ExerciceService::class)->defaultDate();
    $this->showForm = true;
}
```

Dans `save()`, remplacer `'date' => ['required', 'date']` par la validation bornée (même pattern que DepenseForm).

- [ ] **Step 4 : Mettre à jour la vue**

Dans `resources/views/livewire/don-form.blade.php`, remplacer :
```html
wire:click="$set('showForm', true)"
```
par :
```html
wire:click="showNewForm"
```

- [ ] **Step 5 : Lancer les tests**
```bash
./vendor/bin/sail artisan test tests/Livewire/DonFormTest.php
```
Attendu : tous PASS

- [ ] **Step 6 : Commit**
```bash
git add app/Livewire/DonForm.php resources/views/livewire/don-form.blade.php tests/Livewire/DonFormTest.php
git commit -m "feat: DonForm — date bornée à l'exercice actif + showNewForm() + date par défaut"
```

---

### Task 5 : VirementInterneForm

**Files:**
- Modify: `app/Livewire/VirementInterneForm.php`

**Contexte :** La date est initialisée dans `showNewForm()` (ligne 37 : `$this->date = now()->format('Y-m-d')`). Même pattern que DepenseForm. Pas de test Livewire séparé existant — vérification par la suite de tests globale.

- [ ] **Step 1 : Modifier VirementInterneForm**

Ajouter import `ExerciceService`.

Dans `showNewForm()`, remplacer :
```php
$this->date = now()->format('Y-m-d');
```
par :
```php
$this->date = app(ExerciceService::class)->defaultDate();
```

Dans `save()`, ajouter calcul bornes + règle + messages (même pattern).

- [ ] **Step 2 : Lancer la suite complète**
```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 3 : Commit**
```bash
git add app/Livewire/VirementInterneForm.php
git commit -m "feat: VirementInterneForm — date bornée à l'exercice actif + date par défaut intelligente"
```

---

### Task 6 : CotisationForm

**Files:**
- Modify: `app/Livewire/CotisationForm.php`
- Test: `tests/Livewire/CotisationFormTest.php`

**Contexte :** CotisationForm valide `date_paiement`. Deux endroits à mettre à jour :
1. `mount()` : `$this->date_paiement = now()->format('Y-m-d');`
2. Après `save()` réussit, la ligne `$this->date_paiement = now()->format('Y-m-d');` remet la date à aujourd'hui — il faut aussi la remplacer.

- [ ] **Step 1 : Écrire les tests** dans `tests/Livewire/CotisationFormTest.php` :

```php
it('rejette une date_paiement avant le début de l\'exercice', function () {
    $user   = \App\Models\User::factory()->create();
    $membre = \App\Models\Membre::factory()->create();
    session(['exercice_actif' => 2025]);

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\CotisationForm::class, ['membre' => $membre])
        ->set('date_paiement', '2025-08-31')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});

it('rejette une date_paiement après la fin de l\'exercice', function () {
    $user   = \App\Models\User::factory()->create();
    $membre = \App\Models\Membre::factory()->create();
    session(['exercice_actif' => 2025]);

    Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\CotisationForm::class, ['membre' => $membre])
        ->set('date_paiement', '2026-09-01')
        ->set('montant', '50')
        ->set('mode_paiement', 'virement')
        ->call('save')
        ->assertHasErrors(['date_paiement']);
});
```

- [ ] **Step 2 : Lancer pour vérifier l'échec**
```bash
./vendor/bin/sail artisan test tests/Livewire/CotisationFormTest.php
```

- [ ] **Step 3 : Modifier CotisationForm**

Les deux occurrences de `now()->format('Y-m-d')` à remplacer par `app(ExerciceService::class)->defaultDate()` :
- Dans `mount()` : `$this->date_paiement = app(ExerciceService::class)->defaultDate();`
- Dans `save()` après reset : `$this->date_paiement = app(ExerciceService::class)->defaultDate();`

Dans `save()`, remplacer `'date_paiement' => ['required', 'date']` par la validation bornée. Messages : `date_paiement.after_or_equal` et `date_paiement.before_or_equal`.

- [ ] **Step 4 : Lancer les tests**
```bash
./vendor/bin/sail artisan test tests/Livewire/CotisationFormTest.php
```
Attendu : tous PASS

- [ ] **Step 5 : Lancer la suite complète**
```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 6 : Commit**
```bash
git add app/Livewire/CotisationForm.php tests/Livewire/CotisationFormTest.php
git commit -m "feat: CotisationForm — date_paiement bornée à l'exercice actif + date par défaut intelligente"
```
