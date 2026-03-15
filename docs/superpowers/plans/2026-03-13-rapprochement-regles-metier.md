# Rapprochement - Règles métier Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Empêcher la suppression des opérations pointées, permettre la suppression d'un rapprochement "en cours" (avec dépointage automatique), et permettre le déverrouillage du dernier rapprochement verrouillé.

**Architecture:** Guards dans les services métier existants + deux nouvelles méthodes dans `RapprochementBancaireService` + boutons dans les vues Livewire concernées.

**Tech Stack:** Laravel 11, Livewire 4, Pest PHP, Bootstrap 5

---

## Chunk 1: Guards suppression opérations pointées

### Task 1: Guard dans les services de suppression

**Files:**
- Modify: `app/Services/DepenseService.php`
- Modify: `app/Services/RecetteService.php`
- Modify: `app/Services/DonService.php`
- Modify: `app/Services/CotisationService.php`
- Modify: `app/Services/VirementInterneService.php`
- Create: `tests/Feature/Services/SuppressionOperationsPointeesTest.php`

- [ ] **Step 1: Écrire les tests**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Membre;
use App\Models\CompteBancaire;
use App\Models\RapprochementBancaire;
use App\Models\Recette;
use App\Models\User;
use App\Models\VirementInterne;
use App\Services\CotisationService;
use App\Services\DepenseService;
use App\Services\DonService;
use App\Services\RecetteService;
use App\Services\VirementInterneService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
});

test('DepenseService::delete lève une exception si la dépense est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(DepenseService::class)->delete($depense))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('DepenseService::delete réussit si la dépense n\'est pas pointée', function () {
    $depense = Depense::factory()->create(['compte_id' => $this->compte->id]);

    app(DepenseService::class)->delete($depense);

    expect(Depense::find($depense->id))->toBeNull();
});

test('RecetteService::delete lève une exception si la recette est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(RecetteService::class)->delete($recette))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('DonService::delete lève une exception si le don est pointé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $don = Don::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(DonService::class)->delete($don))
        ->toThrow(RuntimeException::class, 'pointé');
});

test('CotisationService::delete lève une exception si la cotisation est pointée', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $membre = Membre::factory()->create();
    $cotisation = Cotisation::factory()->create([
        'membre_id' => $membre->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    expect(fn () => app(CotisationService::class)->delete($cotisation))
        ->toThrow(RuntimeException::class, 'pointée');
});

test('VirementInterneService::delete lève une exception si le virement est pointé côté source', function () {
    $compteDestination = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $this->compte->id,
        'compte_destination_id' => $compteDestination->id,
        'rapprochement_source_id' => $rapprochement->id,
        'saisi_par' => $this->user->id,
    ]);

    expect(fn () => app(VirementInterneService::class)->delete($virement))
        ->toThrow(RuntimeException::class, 'pointé');
});

test('VirementInterneService::delete lève une exception si le virement est pointé côté destination', function () {
    $compteSource = CompteBancaire::factory()->create();
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
    ]);
    $virement = VirementInterne::factory()->create([
        'compte_source_id' => $compteSource->id,
        'compte_destination_id' => $this->compte->id,
        'rapprochement_destination_id' => $rapprochement->id,
        'saisi_par' => $this->user->id,
    ]);

    expect(fn () => app(VirementInterneService::class)->delete($virement))
        ->toThrow(RuntimeException::class, 'pointé');
});
```

- [ ] **Step 2: Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test --filter=SuppressionOperationsPointeesTest
```
Attendu : tous FAIL (no exception thrown)

- [ ] **Step 3: Ajouter les guards dans les services**

Dans `app/Services/DepenseService.php`, méthode `delete()` :
```php
public function delete(Depense $depense): void
{
    if ($depense->rapprochement_id !== null) {
        throw new \RuntimeException("Cette dépense est pointée dans un rapprochement et ne peut pas être supprimée.");
    }

    DB::transaction(function () use ($depense) {
        $depense->lignes()->delete();
        $depense->delete();
    });
}
```

Dans `app/Services/RecetteService.php`, méthode `delete()` :
```php
public function delete(Recette $recette): void
{
    if ($recette->rapprochement_id !== null) {
        throw new \RuntimeException("Cette recette est pointée dans un rapprochement et ne peut pas être supprimée.");
    }

    DB::transaction(function () use ($recette) {
        $recette->lignes()->delete();
        $recette->delete();
    });
}
```

Dans `app/Services/DonService.php`, méthode `delete()` :
```php
public function delete(Don $don): void
{
    if ($don->rapprochement_id !== null) {
        throw new \RuntimeException("Ce don est pointé dans un rapprochement et ne peut pas être supprimé.");
    }

    $don->delete();
}
```

Dans `app/Services/CotisationService.php`, méthode `delete()` :
```php
public function delete(Cotisation $cotisation): void
{
    if ($cotisation->rapprochement_id !== null) {
        throw new \RuntimeException("Cette cotisation est pointée dans un rapprochement et ne peut pas être supprimée.");
    }

    $cotisation->delete();
}
```

Dans `app/Services/VirementInterneService.php`, méthode `delete()` :
```php
public function delete(VirementInterne $virement): void
{
    if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null) {
        throw new \RuntimeException("Ce virement est pointé dans un rapprochement et ne peut pas être supprimé.");
    }

    DB::transaction(function () use ($virement) {
        $virement->delete();
    });
}
```

- [ ] **Step 4: Lancer les tests pour vérifier qu'ils passent**

```bash
./vendor/bin/sail artisan test --filter=SuppressionOperationsPointeesTest
```
Attendu : tous PASS

- [ ] **Step 5: Lancer la suite complète pour détecter les régressions**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/DepenseService.php app/Services/RecetteService.php \
        app/Services/DonService.php app/Services/CotisationService.php \
        app/Services/VirementInterneService.php \
        tests/Feature/Services/SuppressionOperationsPointeesTest.php
git commit -m "feat: bloquer la suppression des opérations pointées dans un rapprochement"
```

---

### Task 2: Désactiver visuellement les boutons de suppression pour les opérations pointées

**Files:**
- Modify: `resources/views/livewire/depense-list.blade.php`
- Modify: `resources/views/livewire/recette-list.blade.php`
- Modify: `resources/views/livewire/don-list.blade.php`
- Modify: `resources/views/livewire/cotisation-list.blade.php` (si existe)
- Modify: `resources/views/livewire/virement-list.blade.php` (si existe)

Note: Pour les composants Livewire qui gèrent la suppression via `delete()`, ajouter la gestion de `RuntimeException` si ce n'est pas déjà fait.

- [ ] **Step 1: Vérifier quelles vues ont un bouton supprimer et si elles chargent `pointe`**

Lire chaque vue et son composant Livewire pour confirmer que `$depense->pointe` est accessible dans la vue.

- [ ] **Step 2: Mettre à jour le bouton supprimer dans `depense-list.blade.php`**

Remplacer le bouton supprimer existant :
```blade
@if ($depense->pointe)
    <button class="btn btn-sm btn-outline-danger" disabled
            title="Dépointez cette dépense avant de la supprimer.">
        <i class="bi bi-trash"></i>
    </button>
@else
    <button wire:click="delete({{ $depense->id }})"
            wire:confirm="Supprimer cette dépense ?"
            class="btn btn-sm btn-outline-danger" title="Supprimer">
        <i class="bi bi-trash"></i>
    </button>
@endif
```

- [ ] **Step 3: Ajouter la gestion d'erreur dans `DepenseList::delete()`**

Dans `app/Livewire/DepenseList.php`, méthode `delete()` :
```php
public function delete(int $id): void
{
    $depense = Depense::findOrFail($id);
    try {
        app(DepenseService::class)->delete($depense);
    } catch (\RuntimeException $e) {
        session()->flash('error', $e->getMessage());
    }
}
```

Ajouter également en haut de la vue `depense-list.blade.php` (si pas déjà présent) :
```blade
@if (session('error'))
    <div class="alert alert-danger alert-dismissible">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
```

- [ ] **Step 4: Répéter pour recette-list, don-list et les autres vues concernées**

Appliquer le même pattern (bouton désactivé si pointé + gestion d'erreur dans le composant) pour chaque vue/composant concerné. Vérifier d'abord la structure de chaque vue avant de modifier.

Pour les virements (`virement-list.blade.php`), la condition est :
```blade
@if ($virement->rapprochement_source_id !== null || $virement->rapprochement_destination_id !== null)
    <button class="btn btn-sm btn-outline-danger" disabled
            title="Dépointez ce virement avant de le supprimer.">
        <i class="bi bi-trash"></i>
    </button>
@else
    {{-- bouton supprimer existant --}}
@endif
```

- [ ] **Step 5: Tester manuellement dans le navigateur**

Naviguer sur `/depenses` et vérifier que les dépenses pointées ont le bouton supprimer grisé. Vérifier que les non-pointées peuvent toujours être supprimées.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/ app/Livewire/
git commit -m "feat: désactiver visuellement la suppression des opérations pointées"
```

---

## Chunk 2: Suppression d'un rapprochement en cours + Déverrouillage

### Task 3: Méthodes `supprimer()` et `deverrouiller()` dans le service

**Files:**
- Modify: `app/Services/RapprochementBancaireService.php`
- Modify: `tests/Feature/Services/RapprochementBancaireServiceTest.php`

- [ ] **Step 1: Écrire les tests pour `supprimer()`**

Ajouter dans `tests/Feature/Services/RapprochementBancaireServiceTest.php` :

```php
test('supprimer supprime un rapprochement en cours et dépointe les opérations', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    $depense = Depense::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);
    $recette = Recette::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->supprimer($rapprochement);

    expect(RapprochementBancaire::find($rapprochement->id))->toBeNull()
        ->and($depense->fresh()->rapprochement_id)->toBeNull()
        ->and($depense->fresh()->pointe)->toBeFalse()
        ->and($recette->fresh()->rapprochement_id)->toBeNull()
        ->and($recette->fresh()->pointe)->toBeFalse();
});

test('supprimer lève une exception si le rapprochement est verrouillé', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'saisi_par' => $this->user->id,
    ]);

    expect(fn () => $this->service->supprimer($rapprochement))
        ->toThrow(RuntimeException::class);
});

test('supprimer dépointe aussi les dons et cotisations', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);
    $don = Don::factory()->create([
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);
    $membre = Membre::factory()->create();
    $cotisation = Cotisation::factory()->create([
        'membre_id' => $membre->id,
        'compte_id' => $this->compte->id,
        'rapprochement_id' => $rapprochement->id,
        'pointe' => true,
    ]);

    $this->service->supprimer($rapprochement);

    expect($don->fresh()->rapprochement_id)->toBeNull()
        ->and($don->fresh()->pointe)->toBeFalse()
        ->and($cotisation->fresh()->rapprochement_id)->toBeNull()
        ->and($cotisation->fresh()->pointe)->toBeFalse();
});
```

Note: Pour les virements internes dans `supprimer()`, on met à null les champs `rapprochement_source_id` et `rapprochement_destination_id` des virements liés.

- [ ] **Step 2: Écrire les tests pour `deverrouiller()`**

```php
test('deverrouiller déverrouille le dernier rapprochement si aucun en cours', function () {
    $rapprochement = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    $this->service->deverrouiller($rapprochement);

    expect($rapprochement->fresh()->statut)->toBe(StatutRapprochement::EnCours)
        ->and($rapprochement->fresh()->verrouille_at)->toBeNull();
});

test('deverrouiller lève une exception si un rapprochement est en cours sur ce compte', function () {
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::EnCours,
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-11-30',
    ]);
    $verrouille = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    expect(fn () => $this->service->deverrouiller($verrouille))
        ->toThrow(RuntimeException::class);
});

test('deverrouiller lève une exception si ce n\'est pas le dernier verrouillé', function () {
    $premier = RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now()->subDay(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-09-30',
    ]);
    RapprochementBancaire::factory()->create([
        'compte_id' => $this->compte->id,
        'statut' => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
        'saisi_par' => $this->user->id,
        'date_fin' => '2025-10-31',
    ]);

    expect(fn () => $this->service->deverrouiller($premier))
        ->toThrow(RuntimeException::class);
});

test('deverrouiller lève une exception si le rapprochement est en cours', function () {
    $rapprochement = $this->service->create($this->compte, '2025-10-31', 1000.00);

    expect(fn () => $this->service->deverrouiller($rapprochement))
        ->toThrow(RuntimeException::class);
});
```

- [ ] **Step 3: Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test --filter=RapprochementBancaireServiceTest
```
Attendu : les nouveaux tests FAIL (method not found)

- [ ] **Step 4: Implémenter `supprimer()` et `deverrouiller()` dans le service**

Dans `app/Services/RapprochementBancaireService.php`, ajouter les imports manquants puis les méthodes :

```php
use App\Models\Cotisation;
use App\Models\Don;
// (Depense, Recette, VirementInterne déjà importés)

/**
 * Supprime un rapprochement "en cours" et dépointe toutes ses opérations.
 * Lève RuntimeException si le rapprochement est verrouillé.
 */
public function supprimer(RapprochementBancaire $rapprochement): void
{
    if ($rapprochement->isVerrouille()) {
        throw new RuntimeException("Impossible de supprimer un rapprochement verrouillé.");
    }

    DB::transaction(function () use ($rapprochement) {
        $id = $rapprochement->id;

        Depense::where('rapprochement_id', $id)
            ->update(['rapprochement_id' => null, 'pointe' => false]);

        Recette::where('rapprochement_id', $id)
            ->update(['rapprochement_id' => null, 'pointe' => false]);

        Don::where('rapprochement_id', $id)
            ->update(['rapprochement_id' => null, 'pointe' => false]);

        Cotisation::where('rapprochement_id', $id)
            ->update(['rapprochement_id' => null, 'pointe' => false]);

        VirementInterne::where('rapprochement_source_id', $id)
            ->update(['rapprochement_source_id' => null]);

        VirementInterne::where('rapprochement_destination_id', $id)
            ->update(['rapprochement_destination_id' => null]);

        $rapprochement->delete();
    });
}

/**
 * Déverrouille le rapprochement s'il est le dernier verrouillé du compte
 * et qu'aucun rapprochement en cours n'existe sur ce compte.
 */
public function deverrouiller(RapprochementBancaire $rapprochement): void
{
    if (! $rapprochement->isVerrouille()) {
        throw new RuntimeException("Ce rapprochement n'est pas verrouillé.");
    }

    $enCours = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
        ->where('statut', StatutRapprochement::EnCours)
        ->exists();

    if ($enCours) {
        throw new RuntimeException("Impossible de déverrouiller : un rapprochement est en cours sur ce compte.");
    }

    $dernierVerrouille = RapprochementBancaire::where('compte_id', $rapprochement->compte_id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->orderByDesc('date_fin')
        ->orderByDesc('id')
        ->value('id');

    if ($dernierVerrouille !== $rapprochement->id) {
        throw new RuntimeException("Seul le dernier rapprochement verrouillé peut être déverrouillé.");
    }

    DB::transaction(function () use ($rapprochement) {
        $rapprochement->statut = StatutRapprochement::EnCours;
        $rapprochement->verrouille_at = null;
        $rapprochement->save();
    });
}
```

- [ ] **Step 5: Lancer les tests**

```bash
./vendor/bin/sail artisan test --filter=RapprochementBancaireServiceTest
```
Attendu : tous PASS

Note sur les `use` dans les tests : les tests Pest n'utilisent pas les imports `use` dans les closures. Vérifier que les classes `Don`, `Cotisation`, `Membre` sont bien importées en haut du fichier de test.

- [ ] **Step 6: Suite complète**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/RapprochementBancaireService.php \
        tests/Feature/Services/RapprochementBancaireServiceTest.php
git commit -m "feat: supprimer un rapprochement en cours et déverrouiller le dernier verrouillé"
```

---

### Task 4: UI — Boutons dans la liste et le détail des rapprochements

**Files:**
- Modify: `app/Livewire/RapprochementList.php`
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `resources/views/livewire/rapprochement-list.blade.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`

**Logique d'affichage :**
- Bouton "Supprimer" : visible sur les rapprochements **en cours**, dans la liste ET dans le détail
- Bouton "Déverrouiller" : visible uniquement sur le rapprochement marqué comme "dernier verrouillé" du compte sélectionné (pas de rapprochement en cours sur ce compte)

- [ ] **Step 1: Ajouter les actions dans `RapprochementList`**

Dans `app/Livewire/RapprochementList.php`, ajouter les méthodes :

```php
public function supprimer(int $id): void
{
    $rapprochement = RapprochementBancaire::findOrFail($id);
    try {
        app(RapprochementBancaireService::class)->supprimer($rapprochement);
        session()->flash('success', 'Rapprochement supprimé.');
    } catch (\RuntimeException $e) {
        session()->flash('error', $e->getMessage());
    }
}

public function deverrouiller(int $id): void
{
    $rapprochement = RapprochementBancaire::findOrFail($id);
    try {
        app(RapprochementBancaireService::class)->deverrouiller($rapprochement);
        session()->flash('success', 'Rapprochement déverrouillé.');
    } catch (\RuntimeException $e) {
        session()->flash('error', $e->getMessage());
    }
}
```

Pour savoir quel est le "dernier verrouillé" déverrouillable, passer l'ID du dernier verrouillé à la vue. Dans `render()`, ajouter :

```php
$dernierVerrouilleId = null;
if ($this->compte_id && ! $aEnCours) {
    $dernierVerrouilleId = RapprochementBancaire::where('compte_id', $this->compte_id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->orderByDesc('date_fin')
        ->orderByDesc('id')
        ->value('id');
}
```

Et passer `$dernierVerrouilleId` à la vue dans le `return view(...)`.

- [ ] **Step 2: Mettre à jour la vue `rapprochement-list.blade.php`**

Ajouter les messages flash en haut de la vue (si pas déjà présent) :
```blade
@if (session('success'))
    <div class="alert alert-success alert-dismissible">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger alert-dismissible">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
```

Remplacer la cellule actions dans le `@foreach` :
```blade
<td>
    <div class="d-flex gap-1">
        <a href="{{ route('rapprochement.detail', $rapprochement) }}"
           class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i>
            {{ $rapprochement->isEnCours() ? 'Continuer' : 'Consulter' }}
        </a>
        @if ($rapprochement->isEnCours())
            <button wire:click="supprimer({{ $rapprochement->id }})"
                    wire:confirm="Supprimer ce rapprochement ? Toutes les écritures pointées seront dépointées."
                    class="btn btn-sm btn-outline-danger" title="Supprimer">
                <i class="bi bi-trash"></i>
            </button>
        @elseif ($rapprochement->id === $dernierVerrouilleId)
            <button wire:click="deverrouiller({{ $rapprochement->id }})"
                    wire:confirm="Déverrouiller ce rapprochement ? Il repassera en statut 'En cours'."
                    class="btn btn-sm btn-outline-warning" title="Déverrouiller">
                <i class="bi bi-unlock"></i>
            </button>
        @endif
    </div>
</td>
```

- [ ] **Step 3: Ajouter le bouton supprimer dans `RapprochementDetail`**

Dans `app/Livewire/RapprochementDetail.php`, ajouter :

```php
public function supprimer(): void
{
    try {
        app(RapprochementBancaireService::class)->supprimer($this->rapprochement);
        $this->redirect(route('rapprochement.index'));
    } catch (\RuntimeException $e) {
        session()->flash('error', $e->getMessage());
    }
}
```

Dans `resources/views/livewire/rapprochement-detail.blade.php`, dans le bloc `@if ($rapprochement->isEnCours())` (section actions), ajouter le bouton supprimer à côté de "Enregistrer et quitter" :

```blade
<button wire:click="supprimer"
        wire:confirm="Supprimer ce rapprochement ? Toutes les écritures pointées seront dépointées."
        class="btn btn-outline-danger">
    <i class="bi bi-trash"></i> Supprimer
</button>
```

- [ ] **Step 4: Tester manuellement**

1. Créer un rapprochement, pointer des opérations, puis supprimer le rapprochement depuis la liste → vérifier que les opérations sont dépointées
2. Depuis le détail, supprimer → vérifier la redirection vers la liste
3. Verrouiller un rapprochement → vérifier que le bouton "Déverrouiller" apparaît dans la liste (et pas si un "en cours" existe)
4. Déverrouiller → vérifier que le statut repasse à "En cours"

- [ ] **Step 5: Suite complète**

```bash
./vendor/bin/sail artisan test
```
Attendu : tous PASS

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/RapprochementList.php app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-list.blade.php \
        resources/views/livewire/rapprochement-detail.blade.php
git commit -m "feat: boutons supprimer (en cours) et déverrouiller (dernier verrouillé) dans les vues rapprochement"
```

---

## Chunk 3: Vérification filtrage par compte (audit)

### Task 5: Confirmer que le filtrage par compte est correct dans RapprochementDetail

**Files:**
- Read only: `app/Livewire/RapprochementDetail.php`

- [ ] **Step 1: Vérifier que chaque requête filtre bien par `compte_id`**

Dans `RapprochementDetail::render()`, chaque type de transaction filtre explicitement par `compte_id` :
- `Depense::where('compte_id', $compte->id)` ✓
- `Recette::where('compte_id', $compte->id)` ✓
- `Don::where('compte_id', $compte->id)` ✓
- `Cotisation::where('compte_id', $compte->id)` ✓
- `VirementInterne::where('compte_source_id', $compte->id)` (virements sortants) ✓
- `VirementInterne::where('compte_destination_id', $compte->id)` (virements entrants) ✓

Aucun changement de code requis — le filtrage est déjà correct.

- [ ] **Step 2: Confirmer via test existant ou manuel**

Les tests existants dans `RapprochementBancaireServiceTest.php` couvrent déjà le comportement du service. La vérification du filtrage par compte dans `toggleTransaction()` est déjà testée implicitement (la méthode vérifie `compte_id` avant de pointer).

- [ ] **Step 3: Pas de commit nécessaire pour ce point (lecture seule)**
