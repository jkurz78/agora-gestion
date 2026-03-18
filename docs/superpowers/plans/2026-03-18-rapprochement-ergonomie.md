# Rapprochement bancaire — Améliorations ergonomie (7 points)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Améliorer l'ergonomie de l'écran de rapprochement bancaire avec 7 améliorations indépendantes.

**Architecture:** Modifications ciblées sur `RapprochementDetail` (Livewire) et `RapprochementPdfController`. Pas de migration, pas de changement de service. Les 5 tâches sont indépendantes et peuvent être commitées séparément.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, DomPDF (Barryvdh), Pest PHP

**Spec:** `docs/superpowers/specs/2026-03-18-rapprochement-ergonomie-design.md`

---

## Fichiers impactés

| Fichier | Tâches |
|---|---|
| `app/Livewire/RapprochementDetail.php` | 1, 2, 3, 4 |
| `resources/views/livewire/rapprochement-detail.blade.php` | 1, 2, 3, 4 |
| `app/Http/Controllers/RapprochementPdfController.php` | 5 |
| `resources/views/pdf/rapprochement.blade.php` | 5 |
| `tests/Feature/Livewire/RapprochementDetailTest.php` | 1, 2, 3, 4 (nouveau) |
| `tests/Feature/RapprochementPdfTest.php` | 5 (extension) |

---

## Task 1 — Colonnes # et Tiers + fix bug eager-load cotisation

**Points spec :** 6 (colonne ID) et 7 (colonne Tiers)

**Contexte :** L'`id` est déjà dans le tableau de données du composant. Le bug : `Cotisation` est chargé avec `->with('membre')` mais la relation s'appelle `tiers()` — corriger en même temps.

**Files:**
- Modify: `app/Livewire/RapprochementDetail.php:133`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php:104`
- Create: `tests/Feature/Livewire/RapprochementDetailTest.php`

- [ ] **Step 1 : Créer le fichier de test Livewire et écrire les tests échouants**

```php
<?php

declare(strict_types=1);

use App\Enums\StatutRapprochement;
use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Don;
use App\Models\RapprochementBancaire;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\User;
use Livewire\Livewire;
use App\Livewire\RapprochementDetail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->compte = CompteBancaire::factory()->create();
    $this->rapprochement = RapprochementBancaire::factory()->create([
        'compte_id'       => $this->compte->id,
        'statut'          => StatutRapprochement::EnCours,
        'solde_ouverture' => 1000.00,
        'solde_fin'       => 1200.00,
        'date_fin'        => '2026-03-31',
        'saisi_par'       => $this->user->id,
    ]);
});

it('affiche la colonne # avec l\'id de la transaction', function () {
    $tx = Transaction::factory()->asRecette()->create([
        'compte_id'       => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date'            => '2026-03-15',
        'montant_total'   => 100.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee((string) $tx->id);
});

it('affiche la colonne Tiers pour une recette', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Dupont', 'prenom' => 'Jean', 'type' => 'particulier']);
    Transaction::factory()->asRecette()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id'         => $tiers->id,
        'date'             => '2026-03-15',
        'montant_total'    => 100.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Jean Dupont');
});

it('affiche la colonne Tiers pour un don', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Martin', 'prenom' => 'Marie', 'type' => 'particulier']);
    Don::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id'         => $tiers->id,
        'date'             => '2026-03-10',
        'montant'          => 50.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Marie Martin');
});

it('affiche la colonne Tiers pour une cotisation via tiers()', function () {
    $tiers = Tiers::factory()->create(['nom' => 'Durand', 'prenom' => 'Pierre', 'type' => 'particulier']);
    Cotisation::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id'         => $tiers->id,
        'date_paiement'    => '2026-03-05',
        'montant'          => 30.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('Pierre Durand');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php --filter="affiche la colonne"
```
Résultat attendu : FAIL (colonnes non présentes)

- [ ] **Step 3 : Modifier `RapprochementDetail.php` — fix eager-load cotisation + ajout clé `tiers`**

Dans `render()`, remplacer le bloc Cotisation (ligne ~122–145) :

```php
// Cotisations
Cotisation::where('compte_id', $compte->id)
    ->where(function ($q) use ($rid, $dateFin, $verrouille) {
        if ($verrouille) {
            $q->where('rapprochement_id', $rid);
        } else {
            $q->where(function ($inner) use ($dateFin) {
                $inner->whereNull('rapprochement_id')
                    ->where('date_paiement', '<=', $dateFin);
            })->orWhere('rapprochement_id', $rid);
        }
    })
    ->with('tiers')
    ->get()
    ->each(function (Cotisation $c) use (&$transactions, $rid) {
        $transactions->push([
            'id'            => $c->id,
            'type'          => 'cotisation',
            'date'          => $c->date_paiement,
            'label'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
            'tiers'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
            'reference'     => null,
            'montant_signe' => (float) $c->montant,
            'pointe'        => (int) $c->rapprochement_id === $rid,
        ]);
    });
```

Ajouter la clé `'tiers'` sur tous les autres types dans `render()` :

**Transactions (dépenses + recettes)** — ajouter `->with('tiers')` avant `->get()`, puis dans le `each()` existant ajouter après `'label'` :
```php
'tiers' => $tx->tiers?->displayName() ?? $tx->libelle,
```

**Dons** — dans le `each()` existant, ajouter après `'label'` :
```php
'tiers' => $d->tiers ? $d->tiers->displayName() : ($d->objet ?? 'Don anonyme'),
```

**Virements sortants** — dans le `each()` existant, ajouter après `'label'` :
```php
'tiers' => $v->compteDestination->nom,
```

**Virements entrants** — dans le `each()` existant, ajouter après `'label'` :
```php
'tiers' => $v->compteSource->nom,
```

- [ ] **Step 4 : Modifier la vue `rapprochement-detail.blade.php` — ajouter colonnes # et Tiers**

Dans le `<thead>`, remplacer :
```html
<tr>
    <th>Date</th>
    <th>Type</th>
    <th>Libellé</th>
    <th>Réf.</th>
    <th class="text-end">Débit</th>
    <th class="text-end">Crédit</th>
    <th class="text-center">Pointé</th>
</tr>
```
par :
```html
<tr>
    <th>#</th>
    <th>Date</th>
    <th>Type</th>
    <th>Libellé</th>
    <th>Tiers</th>
    <th>Réf.</th>
    <th class="text-end">Débit</th>
    <th class="text-end">Crédit</th>
    <th class="text-center">Pointé</th>
</tr>
```

Dans le `<tbody>`, dans la ligne `<tr>`, ajouter avant `<td class="text-nowrap small">{{ $tx['date']...` :
```html
<td class="text-muted small">{{ $tx['id'] }}</td>
```
Et après `<td class="small">{{ $tx['label'] }}</td>` ajouter :
```html
<td class="small text-muted">{{ $tx['tiers'] ?? '—' }}</td>
```

Mettre à jour le `colspan` du message "Aucune transaction" de 7 à 9.

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php --filter="affiche la colonne"
```
Résultat attendu : PASS

- [ ] **Step 6 : Lancer la suite complète pour vérifier l'absence de régression**

```bash
./vendor/bin/sail artisan test
```
Résultat attendu : tous verts

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-detail.blade.php \
        tests/Feature/Livewire/RapprochementDetailTest.php
git commit -m "feat: ajoute colonnes # et Tiers dans le tableau de rapprochement (fix eager-load cotisation)"
```

---

## Task 2 — Totaux débits et crédits pointés

**Point spec :** 2

**Contexte :** Le PDF controller calcule déjà `$totalDebit` / `$totalCredit` depuis la collection `$transactions` via `montant_signe`. Même logique à reproduire dans `render()`.

**Files:**
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`
- Modify: `tests/Feature/Livewire/RapprochementDetailTest.php`

- [ ] **Step 1 : Écrire le test échouant**

Ajouter dans `tests/Feature/Livewire/RapprochementDetailTest.php` :

```php
it('affiche les totaux débits et crédits pointés', function () {
    Transaction::factory()->asDepense()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date'             => '2026-03-10',
        'montant_total'    => 150.00,
    ]);
    Transaction::factory()->asRecette()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date'             => '2026-03-15',
        'montant_total'    => 300.00,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->assertSee('150,00')   // total débit pointé
        ->assertSee('300,00');  // total crédit pointé
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php --filter="totaux"
```

- [ ] **Step 3 : Modifier `render()` dans `RapprochementDetail.php`**

Après `$transactions = $transactions->sortBy('date')->values();`, ajouter :

```php
$totalDebitPointe  = abs($transactions->where('pointe', true)->where('montant_signe', '<', 0)->sum('montant_signe'));
$totalCreditPointe = $transactions->where('pointe', true)->where('montant_signe', '>', 0)->sum('montant_signe');
```

Ajouter ces deux variables au tableau passé à la vue :
```php
return view('livewire.rapprochement-detail', [
    'transactions'       => $transactions,
    'soldePointage'      => $soldePointage,
    'ecart'              => $ecart,
    'totalDebitPointe'   => $totalDebitPointe,
    'totalCreditPointe'  => $totalCreditPointe,
]);
```

- [ ] **Step 4 : Modifier la vue — ajouter 2 cards dans le bandeau de soldes**

Dans le bandeau `.row.g-3.mb-4`, changer les `col-md-3` en `col-md-2` pour les 4 cards existantes et ajouter 2 nouvelles cards après la card "Solde fin" :

```html
<div class="col-md-2">
    <div class="card text-center">
        <div class="card-body py-2">
            <div class="text-muted small">Débits pointés</div>
            <div class="fw-bold text-danger">{{ number_format($totalDebitPointe, 2, ',', ' ') }} €</div>
        </div>
    </div>
</div>
<div class="col-md-2">
    <div class="card text-center">
        <div class="card-body py-2">
            <div class="text-muted small">Crédits pointés</div>
            <div class="fw-bold text-success">{{ number_format($totalCreditPointe, 2, ',', ' ') }} €</div>
        </div>
    </div>
</div>
```

Le bandeau passe ainsi de 4 à 6 cards en `col-md-2` (total = 12 colonnes Bootstrap).

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php
```
Résultat attendu : PASS

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-detail.blade.php \
        tests/Feature/Livewire/RapprochementDetailTest.php
git commit -m "feat: affiche totaux débits et crédits pointés dans le rapprochement"
```

---

## Task 3 — Masquer les écritures pointées

**Point spec :** 3

**Contexte :** Propriété Livewire `$masquerPointees` (bool, `false` par défaut). Filtre sur le résultat de `render()`. État éphémère (non persisté).

**Files:**
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`
- Modify: `tests/Feature/Livewire/RapprochementDetailTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Ajouter dans `tests/Feature/Livewire/RapprochementDetailTest.php` :

```php
it('masque les écritures pointées quand la case est cochée', function () {
    $pointee = Transaction::factory()->asRecette()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date'             => '2026-03-10',
        'montant_total'    => 100.00,
        'libelle'          => 'Recette pointée',
    ]);
    $nonPointee = Transaction::factory()->asRecette()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => null,
        'date'             => '2026-03-15',
        'montant_total'    => 50.00,
        'libelle'          => 'Recette non pointée',
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->set('masquerPointees', true)
        ->assertSee('Recette non pointée')
        ->assertDontSee('Recette pointée');
});

it('affiche toutes les écritures quand la case est décochée', function () {
    Transaction::factory()->asRecette()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'date'             => '2026-03-10',
        'montant_total'    => 100.00,
        'libelle'          => 'Recette pointée',
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->set('masquerPointees', false)
        ->assertSee('Recette pointée');
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php --filter="masque"
```

- [ ] **Step 3 : Modifier `RapprochementDetail.php` — ajouter la propriété et le filtre**

Ajouter la propriété publique après la déclaration de `$rapprochement` :

```php
public bool $masquerPointees = false;
```

Dans `render()`, après `$transactions = $transactions->sortBy('date')->values();` et avant le calcul des totaux, ajouter :

```php
if ($this->masquerPointees) {
    $transactions = $transactions->filter(fn (array $tx) => ! $tx['pointe'])->values();
}
```

- [ ] **Step 4 : Modifier la vue — ajouter la case à cocher**

Dans `rapprochement-detail.blade.php`, juste avant le `<div class="table-responsive">`, ajouter :

```html
@if ($rapprochement->isEnCours())
    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="masquerPointees"
               wire:model.live="masquerPointees">
        <label class="form-check-label small text-muted" for="masquerPointees">
            Masquer les écritures pointées
        </label>
    </div>
@endif
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php
```
Résultat attendu : PASS

- [ ] **Step 6 : Commit**

```bash
git add app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-detail.blade.php \
        tests/Feature/Livewire/RapprochementDetailTest.php
git commit -m "feat: masquer les écritures pointées dans le rapprochement"
```

---

## Task 4 — Champs date_fin et solde_fin modifiables

**Point spec :** 1

**Contexte :** `wire:model.blur` sur deux `<input>`. Méthodes `updateDateFin()` et `updateSoldeFin()` dans le composant. Validation : date ≥ date_fin du dernier rapprochement verrouillé du compte (même ordre que `calculerSoldeOuverture`). Uniquement si `en_cours`.

**Files:**
- Modify: `app/Livewire/RapprochementDetail.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`
- Modify: `tests/Feature/Livewire/RapprochementDetailTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Ajouter dans `tests/Feature/Livewire/RapprochementDetailTest.php` :

```php
it('peut modifier le solde de fin', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateSoldeFin', '1500.50')
        ->assertHasNoErrors();

    expect($this->rapprochement->fresh()->solde_fin)->toEqual('1500.50');
});

it('refuse un solde de fin non numérique', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateSoldeFin', 'abc')
        ->assertHasErrors(['solde_fin']);
});

it('peut modifier la date de fin', function () {
    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateDateFin', '2026-04-30')
        ->assertHasNoErrors();

    expect($this->rapprochement->fresh()->date_fin->format('Y-m-d'))->toBe('2026-04-30');
});

it('refuse une date de fin antérieure au dernier rapprochement verrouillé', function () {
    RapprochementBancaire::factory()->create([
        'compte_id'  => $this->compte->id,
        'statut'     => StatutRapprochement::Verrouille,
        'date_fin'   => '2026-02-28',
        'saisi_par'  => $this->user->id,
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement])
        ->call('updateDateFin', '2026-02-01')
        ->assertHasErrors(['date_fin']);
});

it('ne modifie pas les champs si le rapprochement est verrouillé', function () {
    $this->rapprochement->update([
        'statut'       => StatutRapprochement::Verrouille,
        'verrouille_at' => now(),
    ]);

    Livewire::test(RapprochementDetail::class, ['rapprochement' => $this->rapprochement->fresh()])
        ->call('updateSoldeFin', '9999.00')
        ->assertHasErrors(['solde_fin']);
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php --filter="modifier"
```

- [ ] **Step 3 : Ajouter les méthodes dans `RapprochementDetail.php`**

Ajouter les imports nécessaires en haut :
```php
use App\Enums\StatutRapprochement;
```

Ajouter les deux méthodes publiques après `verrouiller()` :

Ajouter aussi l'import de `Validator` en haut du fichier :
```php
use Illuminate\Support\Facades\Validator;
```

```php
public function updateSoldeFin(string $value): void
{
    if ($this->rapprochement->isVerrouille()) {
        $this->addError('solde_fin', 'Impossible de modifier un rapprochement verrouillé.');
        return;
    }

    $validator = Validator::make(
        ['solde_fin' => $value],
        ['solde_fin' => 'required|numeric'],
        ['solde_fin.required' => 'Le solde de fin est obligatoire.', 'solde_fin.numeric' => 'Le solde de fin doit être un nombre.']
    );
    if ($validator->fails()) {
        $this->addError('solde_fin', $validator->errors()->first('solde_fin'));
        return;
    }

    $this->rapprochement->solde_fin = $value;
    $this->rapprochement->save();
    $this->rapprochement = $this->rapprochement->fresh();
}

public function updateDateFin(string $value): void
{
    if ($this->rapprochement->isVerrouille()) {
        $this->addError('date_fin', 'Impossible de modifier un rapprochement verrouillé.');
        return;
    }

    // Valider le format avant la règle métier
    $validator = Validator::make(
        ['date_fin' => $value],
        ['date_fin' => 'required|date'],
        ['date_fin.required' => 'La date de fin est obligatoire.', 'date_fin.date' => 'La date de fin est invalide.']
    );
    if ($validator->fails()) {
        $this->addError('date_fin', $validator->errors()->first('date_fin'));
        return;
    }

    $dernierVerrouille = RapprochementBancaire::where('compte_id', $this->rapprochement->compte_id)
        ->where('statut', StatutRapprochement::Verrouille)
        ->where('id', '!=', $this->rapprochement->id)
        ->orderByDesc('date_fin')
        ->orderByDesc('id')
        ->first();

    if ($dernierVerrouille && $value < $dernierVerrouille->date_fin->format('Y-m-d')) {
        $this->addError('date_fin', 'La date ne peut pas être antérieure à celle du rapprochement précédent ('.$dernierVerrouille->date_fin->format('d/m/Y').').');
        return;
    }

    $this->rapprochement->date_fin = $value;
    $this->rapprochement->save();
    $this->rapprochement = $this->rapprochement->fresh();
}
```

- [ ] **Step 4 : Modifier la vue — rendre les champs éditables**

Dans `rapprochement-detail.blade.php`, remplacer le bloc d'en-tête (date + statut) :

```html
<div>
    <h4 class="mb-1">{{ $rapprochement->compte->nom }}</h4>
    @if ($rapprochement->isEnCours())
        <div class="d-flex align-items-center gap-2 mt-1">
            <label class="text-muted small mb-0">Relevé du</label>
            <input type="date"
                   wire:change="updateDateFin($event.target.value)"
                   value="{{ $rapprochement->date_fin->format('Y-m-d') }}"
                   class="form-control form-control-sm" style="width:auto">
            <span class="badge bg-warning text-dark ms-1"><i class="bi bi-pencil"></i> En cours</span>
        </div>
        @error('date_fin') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
    @else
        <span class="text-muted">Relevé du {{ $rapprochement->date_fin->format('d/m/Y') }}</span>
        <span class="badge bg-secondary ms-2"><i class="bi bi-lock"></i> Verrouillé</span>
    @endif
</div>
```

Dans la card "Solde fin (relevé)", remplacer la valeur statique :

```html
<div class="card text-center">
    <div class="card-body py-2">
        <div class="text-muted small">Solde fin (relevé)</div>
        @if ($rapprochement->isEnCours())
            <input type="number" step="0.01"
                   wire:change="updateSoldeFin($event.target.value)"
                   value="{{ number_format((float) $rapprochement->solde_fin, 2, '.', '') }}"
                   class="form-control form-control-sm text-center fw-bold" style="width:auto;margin:auto">
            @error('solde_fin') <div class="text-danger" style="font-size:.75rem">{{ $message }}</div> @enderror
        @else
            <div class="fw-bold">{{ number_format((float) $rapprochement->solde_fin, 2, ',', ' ') }} €</div>
        @endif
    </div>
</div>
```

- [ ] **Step 5 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/RapprochementDetailTest.php
```
Résultat attendu : PASS

- [ ] **Step 6 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 7 : Commit**

```bash
git add app/Livewire/RapprochementDetail.php \
        resources/views/livewire/rapprochement-detail.blade.php \
        tests/Feature/Livewire/RapprochementDetailTest.php
git commit -m "feat: date_fin et solde_fin modifiables directement dans le rapprochement en cours"
```

---

## Task 5 — PDF : nommage, bouton Ouvrir, colonnes # et Tiers

**Points spec :** 4, 5, 6 (PDF), 7 (PDF)

**Contexte :** `Association::find(1)` déjà chargé dans le contrôleur. `Str::ascii()` pour le nom de fichier. Paramètre `?mode=inline` pour ouvrir dans le navigateur. Colonnes `id` et `tiers` à ajouter dans `collectTransactions()` et dans la vue PDF. Bug `->with('membre')` sur Cotisation à corriger ici aussi. Pour Transaction, ajouter `->with('tiers')` avant `->get()`.

**Files:**
- Modify: `app/Http/Controllers/RapprochementPdfController.php`
- Modify: `resources/views/pdf/rapprochement.blade.php`
- Modify: `resources/views/livewire/rapprochement-detail.blade.php`
- Modify: `tests/Feature/RapprochementPdfTest.php`

- [ ] **Step 1 : Écrire les tests échouants**

Ajouter dans `tests/Feature/RapprochementPdfTest.php` :

```php
it('télécharge le PDF avec un nom de fichier structuré', function () {
    $assoc = Association::find(1) ?? new Association();
    $assoc->id = 1;
    $assoc->fill(['nom' => 'SVS Association'])->save();

    $response = $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement));

    $response->assertOk();
    $contentDisposition = $response->headers->get('Content-Disposition');
    expect($contentDisposition)->toContain('attachment');
    expect($contentDisposition)->toContain('SVS Association');
    expect($contentDisposition)->toContain('Compte Test');
});

it('ouvre le PDF inline avec ?mode=inline', function () {
    $response = $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement).'?mode=inline');

    $response->assertOk();
    $contentDisposition = $response->headers->get('Content-Disposition');
    expect($contentDisposition)->toContain('inline');
});

it('inclut l\'id et le tiers dans les données PDF', function () {
    $tiers = \App\Models\Tiers::factory()->create(['nom' => 'Test Tiers']);
    Don::factory()->create([
        'compte_id'        => $this->compte->id,
        'rapprochement_id' => $this->rapprochement->id,
        'tiers_id'         => $tiers->id,
        'montant'          => 75.00,
        'date'             => now()->format('Y-m-d'),
    ]);

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(function (string $view, array $data): bool {
            $don = collect($data['transactions'])->first(fn ($t) => $t['type'] === 'Don');
            expect($don)->not->toBeNull();
            expect($don)->toHaveKey('id');
            expect($don)->toHaveKey('tiers');
            return true;
        })
        ->andReturnSelf();

    Pdf::shouldReceive('download')->once()->andReturn(response('', 200));

    $this->actingAs($this->user)
        ->get(route('rapprochement.pdf', $this->rapprochement))
        ->assertOk();
});
```

- [ ] **Step 2 : Lancer les tests pour vérifier qu'ils échouent**

```bash
./vendor/bin/sail artisan test tests/Feature/RapprochementPdfTest.php
```

- [ ] **Step 3 : Modifier `RapprochementPdfController.php`**

Ajouter l'import `Str` :
```php
use Illuminate\Support\Str;
```

Remplacer la méthode `__invoke()` :

```php
public function __invoke(RapprochementBancaire $rapprochement): Response
{
    $rapprochement->load(['compte', 'saisiPar']);
    $compte = $rapprochement->compte;
    $rid = $rapprochement->id;

    $transactions = $this->collectTransactions($compte->id, $rid);

    $totalDebit  = abs($transactions->where('montant_signe', '<', 0)->sum('montant_signe'));
    $totalCredit = $transactions->where('montant_signe', '>', 0)->sum('montant_signe');

    $association = Association::find(1);

    $logoBase64 = null;
    $logoMime = 'image/png';
    if ($association !== null && $association->logo_path !== null) {
        $path = $association->logo_path;
        if (Storage::disk('public')->exists($path)) {
            $logoBase64 = base64_encode(Storage::disk('public')->get($path));
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $logoMime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';
        }
    }

    $data = [
        'rapprochement' => $rapprochement,
        'compte'        => $compte,
        'transactions'  => $transactions,
        'totalDebit'    => $totalDebit,
        'totalCredit'   => $totalCredit,
        'association'   => $association,
        'logoBase64'    => $logoBase64,
        'logoMime'      => $logoMime,
    ];

    // Nommage structuré du fichier
    $dateFin   = $rapprochement->date_fin->format('Y-m-d');
    $comptePart = str_replace('/', '-', Str::ascii($compte->nom));
    $prefix     = $association?->nom
        ? str_replace('/', '-', Str::ascii($association->nom)).' - '
        : '';
    $filename   = $prefix.'Rapprochement '.$comptePart.' au '.$dateFin.'.pdf';

    $pdf = Pdf::loadView('pdf.rapprochement', $data);

    $inline = request()->query('mode') === 'inline';
    return $inline ? $pdf->stream($filename) : $pdf->download($filename);
}
```

Remplacer `collectTransactions()` pour ajouter `id` et `tiers`, et corriger le bug `->with('membre')` sur Cotisation :

```php
private function collectTransactions(int $compteId, int $rid): Collection
{
    $transactions = collect();

    Transaction::where('compte_id', $compteId)
        ->where('rapprochement_id', $rid)
        ->with('tiers')
        ->get()
        ->each(function (Transaction $tx) use (&$transactions) {
            $transactions->push([
                'id'            => $tx->id,
                'date'          => $tx->date,
                'type'          => $tx->type->label(),
                'label'         => $tx->libelle,
                'tiers'         => $tx->tiers?->displayName() ?? $tx->libelle,
                'reference'     => $tx->reference ?? null,
                'montant_signe' => $tx->montantSigne(),
            ]);
        });

    Don::where('compte_id', $compteId)
        ->where('rapprochement_id', $rid)
        ->with('tiers')
        ->get()
        ->each(function (Don $d) use (&$transactions) {
            $transactions->push([
                'id'            => $d->id,
                'date'          => $d->date,
                'type'          => 'Don',
                'label'         => $d->tiers ? $d->tiers->displayName() : ($d->objet ?? 'Don anonyme'),
                'tiers'         => $d->tiers ? $d->tiers->displayName() : ($d->objet ?? 'Don anonyme'),
                'reference'     => null,
                'montant_signe' => (float) $d->montant,
            ]);
        });

    Cotisation::where('compte_id', $compteId)
        ->where('rapprochement_id', $rid)
        ->with('tiers')
        ->get()
        ->each(function (Cotisation $c) use (&$transactions) {
            $transactions->push([
                'id'            => $c->id,
                'date'          => $c->date_paiement,
                'type'          => 'Cotisation',
                'label'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
                'tiers'         => $c->tiers ? $c->tiers->displayName() : 'Cotisation',
                'reference'     => null,
                'montant_signe' => (float) $c->montant,
            ]);
        });

    VirementInterne::where('compte_source_id', $compteId)
        ->where('rapprochement_source_id', $rid)
        ->with('compteDestination')
        ->get()
        ->each(function (VirementInterne $v) use (&$transactions) {
            $transactions->push([
                'id'            => $v->id,
                'date'          => $v->date,
                'type'          => 'Virement sortant',
                'label'         => 'Virement vers '.$v->compteDestination->nom,
                'tiers'         => $v->compteDestination->nom,
                'reference'     => $v->reference ?? null,
                'montant_signe' => -(float) $v->montant,
            ]);
        });

    VirementInterne::where('compte_destination_id', $compteId)
        ->where('rapprochement_destination_id', $rid)
        ->with('compteSource')
        ->get()
        ->each(function (VirementInterne $v) use (&$transactions) {
            $transactions->push([
                'id'            => $v->id,
                'date'          => $v->date,
                'type'          => 'Virement entrant',
                'label'         => 'Virement depuis '.$v->compteSource->nom,
                'tiers'         => $v->compteSource->nom,
                'reference'     => $v->reference ?? null,
                'montant_signe' => (float) $v->montant,
            ]);
        });

    return $transactions->sortBy('date')->values();
}
```

- [ ] **Step 4 : Modifier la vue PDF `resources/views/pdf/rapprochement.blade.php`**

Dans `<thead>`, remplacer les colonnes (et ajuster les largeurs pour 8 colonnes) :

```html
<thead>
    <tr>
        <th style="width: 5%;">#</th>
        <th style="width: 9%;">Date</th>
        <th style="width: 11%;">Type</th>
        <th>Libellé</th>
        <th>Tiers</th>
        <th style="width: 10%;">Réf.</th>
        <th class="text-end" style="width: 10%;">Débit</th>
        <th class="text-end" style="width: 10%;">Crédit</th>
    </tr>
</thead>
```

Dans `<tbody>`, ajouter les cellules correspondantes (avant `{{ $tx['date'] }}`) :

```html
<td>{{ $tx['id'] }}</td>
```

Et après `<td>{{ $tx['label'] }}</td>` :

```html
<td>{{ $tx['tiers'] ?? '—' }}</td>
```

Dans `<tfoot>`, mettre à jour le `colspan` de 4 à 6 :

```html
<td colspan="6">Total</td>
```

- [ ] **Step 5 : Ajouter le bouton "Ouvrir" dans la vue Livewire**

Dans `rapprochement-detail.blade.php`, remplacer le bouton PDF existant :

```html
<a href="{{ route('rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-file-pdf"></i> Télécharger PDF
</a>
```

par :

```html
<a href="{{ route('rapprochement.pdf', $rapprochement) }}" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-download"></i> Télécharger PDF
</a>
<a href="{{ route('rapprochement.pdf', $rapprochement) }}?mode=inline" target="_blank" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-file-pdf"></i> Ouvrir PDF
</a>
```

- [ ] **Step 6 : Lancer les tests**

```bash
./vendor/bin/sail artisan test tests/Feature/RapprochementPdfTest.php
```
Résultat attendu : PASS

- [ ] **Step 7 : Lancer la suite complète**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 8 : Commit**

```bash
git add app/Http/Controllers/RapprochementPdfController.php \
        resources/views/pdf/rapprochement.blade.php \
        resources/views/livewire/rapprochement-detail.blade.php \
        tests/Feature/RapprochementPdfTest.php
git commit -m "feat: nommage PDF structuré, bouton Ouvrir inline, colonnes # et Tiers dans le PDF"
```
