# Lot 1 — Modaux autonomes (DonForm, CotisationForm, VirementInterneForm)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir DonForm, CotisationForm et VirementInterneForm en composants modaux autonomes invocables depuis n'importe quel écran via des événements Livewire standardisés.

**Architecture:** Chaque formulaire perd son bouton "Nouveau X" intégré et devient un overlay pur activé par événement Livewire (`open-don-form`, `open-cotisation-form`, `open-virement-form`). Les composants sont déplacés dans le layout principal (`app.blade.php`) pour être disponibles globalement. Les listes gagnent leurs propres boutons "Nouveau" qui dispatchent ces événements.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5 CDN, Pest PHP

---

## Fichiers modifiés

| Fichier | Action |
|---|---|
| `app/Livewire/DonForm.php` | Ajouter `#[On('open-don-form')]`, supprimer `showNewForm()` |
| `app/Livewire/CotisationForm.php` | Ajouter `#[On('open-cotisation-form')]` avec support édition |
| `app/Livewire/VirementInterneForm.php` | Ajouter `#[On('open-virement-form')]`, supprimer `showNewForm()` |
| `resources/views/livewire/don-form.blade.php` | Supprimer le bloc bouton "Nouveau don" |
| `resources/views/livewire/cotisation-form.blade.php` | Supprimer le bloc bouton "Nouvelle cotisation" si présent |
| `resources/views/livewire/virement-interne-form.blade.php` | Supprimer le bloc bouton "Nouveau virement" si présent |
| `resources/views/livewire/don-list.blade.php` | Ajouter bouton "Nouveau don" → `dispatch('open-don-form')` |
| `resources/views/livewire/cotisation-list.blade.php` | Ajouter bouton "Nouvelle cotisation" → `dispatch('open-cotisation-form')` |
| `resources/views/livewire/virement-interne-list.blade.php` | Adapter bouton → `dispatch('open-virement-form')` |
| `resources/views/layouts/app.blade.php` | Inclure les 3 composants formulaires globalement |
| `resources/views/dons/index.blade.php` | Supprimer `<livewire:don-form />` (déplacé dans layout) |
| `resources/views/cotisations/index.blade.php` | Supprimer `<livewire:cotisation-form />` |
| `resources/views/virements/index.blade.php` | Supprimer `<livewire:virement-interne-form />` |
| `resources/views/membres/index.blade.php` | Supprimer `<livewire:cotisation-form />` (déplacé dans layout) |
| `tests/Feature/Livewire/DonFormTest.php` | Créer — tests événements open-don-form |
| `tests/Feature/Livewire/CotisationFormTest.php` | Créer — tests événements open-cotisation-form |
| `tests/Feature/Livewire/VirementInterneFormTest.php` | Créer — tests événements open-virement-form |

---

## Task 1 : DonForm — événement standardisé

**Fichiers :**
- Modifier : `app/Livewire/DonForm.php`
- Modifier : `resources/views/livewire/don-form.blade.php`
- Créer : `tests/Feature/Livewire/DonFormTest.php`

### Contexte

`DonForm` a actuellement :
- Un `$showForm` booléen qui contrôle la visibilité
- Un `showNewForm()` appelé via `wire:click` dans la vue
- Un `#[On('edit-don')]` qui charge un don existant
- Le bouton "Nouveau don" **dans la vue du formulaire** (pas dans la liste)

L'objectif : remplacer ces mécanismes par un unique listener `#[On('open-don-form')]` gérant les deux cas (nouveau / édition).

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/Feature/Livewire/DonFormTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\DonForm;
use App\Models\Don;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour un nouveau don via open-don-form', function () {
    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('donId', null);
});

it('s\'ouvre en édition via open-don-form avec un id', function () {
    $don = Don::factory()->create(['date' => '2025-10-01']);

    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: $don->id)
        ->assertSet('showForm', true)
        ->assertSet('donId', $don->id)
        ->assertSet('montant', $don->montant);
});

it('se ferme via resetForm', function () {
    Livewire::test(DonForm::class)
        ->dispatch('open-don-form', id: null)
        ->call('resetForm')
        ->assertSet('showForm', false);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/DonFormTest.php --no-coverage
```

Expected : FAIL — `open-don-form` n'est pas encore écouté.

- [ ] **Step 3 : Ajouter le listener `open-don-form` dans DonForm.php**

Dans `app/Livewire/DonForm.php`, ajouter en haut des imports :

```php
use Livewire\Attributes\On;
```

Remplacer la méthode `showNewForm()` et le listener `#[On('edit-don')]` par :

```php
#[On('open-don-form')]
public function open(?int $id = null): void
{
    $this->resetForm();
    if ($id !== null) {
        $don = Don::findOrFail($id);
        $this->donId = $don->id;
        $this->date = $don->date->format('Y-m-d');
        $this->montant = $don->montant;
        $this->mode_paiement = $don->mode_paiement->value;
        $this->objet = $don->objet ?? '';
        $this->tiers_id = $don->tiers_id;
        $this->sous_categorie_id = $don->sous_categorie_id;
        $this->operation_id = $don->operation_id;
        $this->seance = $don->seance ?? null;  // ?int, pas de chaîne vide
        $this->compte_id = $don->compte_id;
    } else {
        // Reprendre les défauts de showNewForm() : date par défaut + dernière sous-catégorie
        $exerciceService = app(\App\Services\ExerciceService::class);
        $this->date = $exerciceService->defaultDate()->format('Y-m-d');
        // sous_categorie_id : reprendre la logique existante de showNewForm() si présente
    }
    $this->showForm = true;
}
```

> **Note :** Conserver `#[On('edit-don')]` temporairement comme alias appelant `open()`, pour ne pas casser don-list avant de le mettre à jour dans la Task 5.

```php
#[On('edit-don')]
public function editDon(int $id): void
{
    $this->open($id);
}
```

- [ ] **Step 4 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/DonFormTest.php --no-coverage
```

Expected : PASS (3 tests).

- [ ] **Step 5 : Supprimer le bouton "Nouveau don" de don-form.blade.php**

Dans `resources/views/livewire/don-form.blade.php`, supprimer le bloc :

```html
@if (! $showForm)
    <div class="mb-3">
        <button wire:click="showNewForm" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Nouveau don
        </button>
    </div>
@else
```

Et supprimer le `@endif` correspondant. La vue commence maintenant directement par le `@if($showForm)` qui conditionne l'overlay, ou mieux : supprimer le `@if` et laisser l'overlay toujours dans le DOM (caché par `$showForm` via `@if`).

La vue doit ressembler à :

```html
<div>
    @if($showForm)
    <div class="position-fixed top-0 start-0 w-100 h-100"
         style="background:rgba(0,0,0,.5);z-index:1040;overflow-y:auto"
         wire:click.self="resetForm">
        {{-- ... reste du formulaire inchangé ... --}}
    </div>
    @endif
</div>
```

- [ ] **Step 6 : Relancer tous les tests du projet**

```bash
./vendor/bin/sail artisan test --no-coverage
```

Expected : PASS. Si des tests liés à `showNewForm` échouent, les corriger.

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/DonForm.php resources/views/livewire/don-form.blade.php tests/Feature/Livewire/DonFormTest.php
git commit -m "feat(lot1): DonForm - événement open-don-form, supprime bouton intégré"
```

---

## Task 2 : CotisationForm — événement standardisé + support édition

**Fichiers :**
- Modifier : `app/Livewire/CotisationForm.php`
- Modifier : `resources/views/livewire/cotisation-form.blade.php`
- Créer : `tests/Feature/Livewire/CotisationFormTest.php`

### Contexte

`CotisationForm` n'a **pas de méthode d'édition** actuellement (uniquement création). Le spec prévoit une édition avec le tiers verrouillé. Il faut donc ajouter la méthode `edit()` en plus du listener.

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/Feature/Livewire/CotisationFormTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\CotisationForm;
use App\Models\Cotisation;
use App\Models\Tiers;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour une nouvelle cotisation via open-cotisation-form', function () {
    Livewire::test(CotisationForm::class)
        ->dispatch('open-cotisation-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('cotisationId', null);
});

it('s\'ouvre en édition avec tiers verrouillé via open-cotisation-form', function () {
    $cotisation = Cotisation::factory()->create(['date_paiement' => '2025-10-01']);

    Livewire::test(CotisationForm::class)
        ->dispatch('open-cotisation-form', id: $cotisation->id)
        ->assertSet('showForm', true)
        ->assertSet('cotisationId', $cotisation->id)
        ->assertSet('tiers_id', $cotisation->tiers_id)
        ->assertSet('tiersLocked', true);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/CotisationFormTest.php --no-coverage
```

Expected : FAIL.

- [ ] **Step 3 : Mettre à jour CotisationForm.php**

Ajouter la propriété `$cotisationId` et `$tiersLocked` :

```php
public ?int $cotisationId = null;
public bool $tiersLocked = false;
```

Ajouter le listener et la méthode d'édition :

```php
#[On('open-cotisation-form')]
public function open(?int $id = null): void
{
    $this->resetForm();
    if ($id !== null) {
        $cotisation = Cotisation::findOrFail($id);
        $this->cotisationId = $cotisation->id;
        $this->tiers_id = $cotisation->tiers_id;
        $this->sous_categorie_id = $cotisation->sous_categorie_id;
        $this->montant = $cotisation->montant;
        $this->date_paiement = $cotisation->date_paiement->format('Y-m-d');
        $this->mode_paiement = $cotisation->mode_paiement->value;
        $this->compte_id = $cotisation->compte_id;
        $this->tiersLocked = true;
    }
    $this->showForm = true;
}
```

Mettre à jour `save()` pour gérer la mise à jour si `$cotisationId` est défini (appeler `CotisationService::update()` ou `update()` via Eloquent selon ce que le service expose). Si le service ne supporte pas encore l'édition, ajouter une méthode `update()` minimale dans `CotisationService`.

**Conserver l'alias `open-cotisation-for-tiers`** — ce listener est appelé depuis `membre-list.blade.php` (bouton "Cotisation rapide"). Ne pas le supprimer, juste le faire déléguer à `open()` :

```php
#[On('open-cotisation-for-tiers')]
public function openForTiers(?int $tiersId = null): void
{
    $this->open(null);
    if ($tiersId) {
        $this->tiers_id = $tiersId;
        $this->tiersLocked = true;
    }
}
```

Mettre à jour `resetForm()` pour réinitialiser `$cotisationId` et `$tiersLocked` :

```php
public function resetForm(): void
{
    $this->cotisationId = null;
    $this->tiersLocked = false;
    $this->tiers_id = null;
    // ... autres reset existants ...
    $this->showForm = false;
}
```

- [ ] **Step 4 : Mettre à jour cotisation-form.blade.php**

Rendre le champ tiers en lecture seule quand `$tiersLocked` est vrai :

```html
@if($tiersLocked)
    <div class="mb-3">
        <label class="form-label">Membre</label>
        <input type="text" class="form-control" readonly
               value="{{ $tiers_id ? \App\Models\Tiers::find($tiers_id)?->nom_complet : '' }}">
    </div>
@else
    {{-- champ autocomplete tiers existant --}}
@endif
```

Supprimer le bouton "Nouvelle cotisation" intégré si présent (même logique que DonForm).

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/CotisationFormTest.php --no-coverage
```

Expected : PASS.

- [ ] **Step 6 : Relancer tous les tests du projet**

```bash
./vendor/bin/sail artisan test --no-coverage
```

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/CotisationForm.php resources/views/livewire/cotisation-form.blade.php tests/Feature/Livewire/CotisationFormTest.php
git commit -m "feat(lot1): CotisationForm - événement open-cotisation-form, support édition tiers verrouillé"
```

---

## Task 3 : VirementInterneForm — événement standardisé

**Fichiers :**
- Modifier : `app/Livewire/VirementInterneForm.php`
- Modifier : `resources/views/livewire/virement-interne-form.blade.php`
- Créer : `tests/Feature/Livewire/VirementInterneFormTest.php`

### Contexte

`VirementInterneForm` a déjà `#[On('edit-virement')]` pour l'édition. Ajouter `#[On('open-virement-form')]` comme alias unifié.

- [ ] **Step 1 : Écrire le test qui échoue**

Créer `tests/Feature/Livewire/VirementInterneFormTest.php` :

```php
<?php

declare(strict_types=1);

use App\Livewire\VirementInterneForm;
use App\Models\VirementInterne;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('s\'ouvre pour un nouveau virement via open-virement-form', function () {
    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: null)
        ->assertSet('showForm', true)
        ->assertSet('virementId', null);
});

it('s\'ouvre en édition via open-virement-form avec un id', function () {
    $virement = VirementInterne::factory()->create(['date' => '2025-10-01']);

    Livewire::test(VirementInterneForm::class)
        ->dispatch('open-virement-form', id: $virement->id)
        ->assertSet('showForm', true)
        ->assertSet('virementId', $virement->id)
        ->assertSet('montant', $virement->montant);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/VirementInterneFormTest.php --no-coverage
```

Expected : FAIL.

- [ ] **Step 3 : Ajouter le listener dans VirementInterneForm.php**

```php
#[On('open-virement-form')]
public function open(?int $id = null): void
{
    $this->resetForm();
    if ($id !== null) {
        $virement = VirementInterne::findOrFail($id);
        $this->virementId = $virement->id;
        $this->date = $virement->date->format('Y-m-d');
        $this->montant = $virement->montant;
        $this->compte_source_id = $virement->compte_source_id;
        $this->compte_destination_id = $virement->compte_destination_id;
        $this->reference = $virement->reference ?? '';
        $this->notes = $virement->notes ?? '';
    }
    $this->showForm = true;
}
```

Conserver `#[On('edit-virement')]` comme alias pour ne pas casser virement-interne-list :

```php
#[On('edit-virement')]
public function editVirement(int $id): void
{
    $this->open($id);
}
```

Supprimer `showNewForm()` si présente.

- [ ] **Step 4 : Supprimer le bouton "Nouveau virement" de virement-interne-form.blade.php** si présent (même pattern que DonForm).

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/VirementInterneFormTest.php --no-coverage
```

Expected : PASS.

- [ ] **Step 6 : Relancer tous les tests du projet**

```bash
./vendor/bin/sail artisan test --no-coverage
```

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/VirementInterneForm.php resources/views/livewire/virement-interne-form.blade.php tests/Feature/Livewire/VirementInterneFormTest.php
git commit -m "feat(lot1): VirementInterneForm - événement open-virement-form"
```

---

## Task 4 : Déplacer les formulaires dans le layout principal

**Fichiers :**
- Modifier : `resources/views/layouts/app.blade.php`
- Modifier : `resources/views/dons/index.blade.php`
- Modifier : `resources/views/cotisations/index.blade.php`
- Modifier : `resources/views/virements/index.blade.php`

### Contexte

Actuellement, chaque formulaire est inclus dans sa page dédiée (`dons/index.blade.php`, etc.). Pour qu'ils soient invocables depuis n'importe quel écran (notamment TransactionUniverselle en Lot 2), ils doivent vivre dans le layout.

- [ ] **Step 1 : Ajouter les 3 composants dans app.blade.php**

Localiser la balise `@livewireScripts` dans `resources/views/layouts/app.blade.php`. Juste avant, ajouter :

```html
{{-- Formulaires modaux globaux --}}
<livewire:don-form />
<livewire:cotisation-form />
<livewire:virement-interne-form />

@livewireScripts
```

- [ ] **Step 2 : Supprimer les inclusions des pages dédiées**

Dans `resources/views/dons/index.blade.php` :

```html
<x-app-layout>
    <h1 class="mb-4">Dons</h1>
    <livewire:don-list />
</x-app-layout>
```

Dans `resources/views/cotisations/index.blade.php` :

```html
<x-app-layout>
    <h1 class="mb-4">Cotisations</h1>
    <livewire:cotisation-list />
</x-app-layout>
```

Dans `resources/views/virements/index.blade.php` :

```html
<x-app-layout>
    <h1 class="mb-4">Virements internes</h1>
    <livewire:virement-interne-list />
</x-app-layout>
```

Dans `resources/views/membres/index.blade.php` : supprimer `<livewire:cotisation-form />` (maintenant dans le layout).

> **Note :** Entre la fin de Task 4 et la fin de Task 5, les boutons "Nouveau" ont été retirés des vues de formulaire (Tasks 1–3) mais pas encore ajoutés aux listes. Cette fenêtre est transitoire et acceptable sur une branche de développement — ne pas déployer en production entre ces deux tasks.

- [ ] **Step 3 : Vérifier manuellement dans le navigateur**

Ouvrir `http://localhost/dons`. Le formulaire ne doit **pas** être visible au chargement. Cliquer "Nouveau don" → le formulaire doit s'ouvrir. Cliquer hors du formulaire → il se ferme.

Ouvrir `http://localhost/cotisations` et `http://localhost/virements` — même vérification.

- [ ] **Step 4 : Relancer tous les tests du projet**

```bash
./vendor/bin/sail artisan test --no-coverage
```

Expected : PASS.

- [ ] **Step 5 : Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/dons/index.blade.php resources/views/cotisations/index.blade.php resources/views/virements/index.blade.php
git commit -m "feat(lot1): déplace les formulaires modaux dans le layout principal"
```

---

## Task 5 : Boutons "Nouveau" dans les listes + nettoyage des alias

**Fichiers :**
- Modifier : `resources/views/livewire/don-list.blade.php`
- Modifier : `resources/views/livewire/cotisation-list.blade.php`
- Modifier : `resources/views/livewire/virement-interne-list.blade.php`
- Modifier : `app/Livewire/DonForm.php` (supprimer alias `edit-don`)
- Modifier : `app/Livewire/VirementInterneForm.php` (supprimer alias `edit-virement`)

- [ ] **Step 1 : Ajouter le bouton "Nouveau don" dans don-list.blade.php**

En haut du composant, avant la section filtres :

```html
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0">Dons</h2>
    <button wire:click="$dispatch('open-don-form', { id: null })"
            class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nouveau don
    </button>
</div>
```

- [ ] **Step 2 : Mettre à jour le bouton "Modifier" de don-list.blade.php**

Remplacer `dispatch('edit-don', { id: ... })` par `dispatch('open-don-form', { id: ... })` :

```html
<button wire:click="$dispatch('open-don-form', { id: {{ $don->id }} })"
        class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-pencil"></i>
</button>
```

- [ ] **Step 3 : Ajouter le bouton "Nouvelle cotisation" dans cotisation-list.blade.php**

```html
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0">Cotisations</h2>
    <button wire:click="$dispatch('open-cotisation-form', { id: null })"
            class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg"></i> Nouvelle cotisation
    </button>
</div>
```

Ajouter bouton "Modifier" dans la liste (il n'existait pas) :

```html
<button wire:click="$dispatch('open-cotisation-form', { id: {{ $cotisation->id }} })"
        class="btn btn-sm btn-outline-secondary"
        @if($cotisation->pointe) disabled @endif>
    <i class="bi bi-pencil"></i>
</button>
```

- [ ] **Step 4 : Mettre à jour virement-interne-list.blade.php**

Ajouter le bouton "Nouveau virement" si absent, et mettre à jour le bouton "Modifier" :

```html
<button wire:click="$dispatch('open-virement-form', { id: {{ $virement->id }} })"
        class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-pencil"></i>
</button>
```

- [ ] **Step 5 : Supprimer les alias dans les composants PHP**

Dans `DonForm.php`, supprimer la méthode `editDon()` avec `#[On('edit-don')]`.
Dans `VirementInterneForm.php`, supprimer la méthode `editVirement()` avec `#[On('edit-virement')]`.

- [ ] **Step 6 : Relancer tous les tests du projet**

```bash
./vendor/bin/sail artisan test --no-coverage
```

Expected : PASS.

- [ ] **Step 7 : Vérification manuelle complète**

Tester sur chaque écran :
- `http://localhost/dons` : Nouveau don, Modifier don, fermeture du modal
- `http://localhost/cotisations` : Nouvelle cotisation, Modifier cotisation (tiers grisé)
- `http://localhost/virements` : Nouveau virement, Modifier virement

- [ ] **Step 8 : Commit final**

```bash
git add resources/views/livewire/don-list.blade.php resources/views/livewire/cotisation-list.blade.php resources/views/livewire/virement-interne-list.blade.php app/Livewire/DonForm.php app/Livewire/VirementInterneForm.php
git commit -m "feat(lot1): boutons Nouveau dans les listes, suppression des alias d'événements"
```

---

## Récapitulatif des événements après Lot 1

| Événement | Composant | `id = null` | `id = X` |
|---|---|---|---|
| `open-don-form` | DonForm | Nouveau don | Édition don X |
| `open-cotisation-form` | CotisationForm | Nouvelle cotisation | Édition cotisation X (tiers verrouillé) |
| `open-virement-form` | VirementInterneForm | Nouveau virement | Édition virement X |
| `open-transaction-form` | TransactionForm (existant) | *(déjà implémenté)* | *(déjà implémenté)* |
