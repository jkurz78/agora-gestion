# Lots 3–7 : Remplacement des listes par TransactionUniverselle

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer les cinq listes existantes (TransactionList, TransactionCompteList, TiersTransactions, DonList, CotisationList) par le composant `TransactionUniverselle` déjà implémenté, en passant les props adéquates.

**Architecture:** Chaque remplacement consiste à (1) réécrire la vue Blade pour utiliser `<livewire:transaction-universelle>` avec les bonnes props, (2) adapter la route si nécessaire, (3) mettre à jour la nav globale. Les anciens composants Livewire ne sont pas supprimés (tests existants restent valides). La route `/transactions/all` provisoire est supprimée après que `/transactions` la remplace.

**Tech Stack:** Laravel 11, Livewire 4, Bootstrap 5, Blade

---

## Fichiers touchés

| Fichier | Action |
|---------|--------|
| `resources/views/transactions/index.blade.php` | Modifier |
| `resources/views/transactions/all.blade.php` | Supprimer |
| `resources/views/comptes-bancaires/transactions.blade.php` | Modifier |
| `resources/views/tiers/transactions.blade.php` | Modifier |
| `resources/views/dons/index.blade.php` | Modifier |
| `resources/views/cotisations/index.blade.php` | Modifier |
| `routes/web.php` | Modifier (supprimer transactions.all, garder les autres) |
| `resources/views/layouts/app.blade.php` | Modifier (nav) |
| `tests/Feature/Livewire/TransactionUniverselleTest.php` | Modifier (ajouter smoke tests) |

---

## Contexte important

### Props de TransactionUniverselle

```php
// app/Livewire/TransactionUniverselle.php
public ?int $compteId = null;      // verrouille sur un compte (pas de dropdown QBE)
public ?int $tiersId = null;       // verrouille sur un tiers
public ?array $lockedTypes = null; // ['depense','recette','don','cotisation','virement'] ou sous-ensemble
public ?int $exercice = null;      // null = exercice courant
```

- `lockedTypes = null` → tous les types (DEP, REC, DON, COT, VIR)
- `lockedTypes = ['depense','recette']` → DEP+REC uniquement, boutons de filtre restreints
- `lockedTypes = ['don']` → DON uniquement, pas de boutons de filtre de type
- `tiersId` non null → virements exclus automatiquement (`whereRaw('1=0')` dans le service)

### Route transactions.all à supprimer

`/transactions/all` était une route provisoire de recette (Lot 2). Elle est remplacée par `/transactions` dans ce lot.

### Nav actuelle (app.blade.php ~L128)

```blade
<a href="{{ route('transactions.index') }}">Toutes</a>   ← DEP+REC (TransactionList)
<a href="{{ route('transactions.all') }}">Toutes (nouveau)</a>  ← provisoire à supprimer
<a href="{{ route('transactions.index') }}?type=depense">Dépenses</a>  ← à supprimer
<a href="{{ route('transactions.index') }}?type=recette">Recettes</a>  ← à supprimer
```

---

## Task 1 : Lot 3 — Remplacer `/transactions`

**Files:**
- Modify: `resources/views/transactions/index.blade.php`
- Delete: `resources/views/transactions/all.blade.php`
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`

- [ ] **Step 1 : Écrire le test qui vérifie que la page /transactions rend TransactionUniverselle**

```php
// tests/Feature/Livewire/TransactionUniverselleTest.php — ajouter à la fin :

it('la page /transactions rend TransactionUniverselle avec lockedTypes depense+recette', function () {
    $this->get('/transactions')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['depense', 'recette']])
        ->assertSet('lockedTypes', ['depense', 'recette']);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il passe déjà ou échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php --filter="page.*transactions"
```

- [ ] **Step 3 : Réécrire `resources/views/transactions/index.blade.php`**

```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-list-ul me-2"></i>Transactions</h4>
        <livewire:transaction-universelle :lockedTypes="['depense', 'recette']" />
    </div>
</x-app-layout>
```

- [ ] **Step 4 : Supprimer `resources/views/transactions/all.blade.php`**

```bash
rm resources/views/transactions/all.blade.php
```

- [ ] **Step 5 : Mettre à jour `routes/web.php` — supprimer la route transactions.all**

Trouver et supprimer cette ligne :
```php
Route::view('/transactions/all', 'transactions.all')->name('transactions.all');
```

- [ ] **Step 6 : Mettre à jour la nav dans `resources/views/layouts/app.blade.php`**

Localiser le bloc dropdown "Transactions" (~L128). Remplacer le contenu du menu déroulant Transactions par :

```blade
<a class="dropdown-item {{ request()->routeIs('transactions.index') ? 'active' : '' }}"
   href="{{ route('transactions.index') }}">
    <i class="bi bi-list-ul me-1"></i> Transactions
</a>
```

Supprimer les items :
- l'item "Toutes (nouveau)" → `transactions.all`
- l'item "Dépenses" → `transactions.index?type=depense`
- l'item "Recettes" → `transactions.index?type=recette`

Mettre à jour le `routeIs` actif sur le `<a class="nav-link dropdown-toggle">` parent :
```blade
{{ request()->routeIs('transactions.*') || request()->routeIs('virements.*') || request()->routeIs('dons.*') || request()->routeIs('cotisations.*') ? 'active' : '' }}
```
→ retirer `transactions.all` du test routeIs (il n'existe plus) — la condition `transactions.*` couvre déjà tout.

- [ ] **Step 7 : Lancer tous les tests**

```bash
./vendor/bin/sail artisan test
```

Expected : aucun test cassé. Si `TransactionListTest` échoue car il cherche une vue, vérifier — on n'a pas supprimé TransactionList.php, seulement sa vue. Si la vue manque, les tests Livewire du composant (qui testent le composant directement, pas la route) restent valides.

- [ ] **Step 8 : Commit**

```bash
git add resources/views/transactions/index.blade.php \
        resources/views/layouts/app.blade.php \
        routes/web.php \
        tests/Feature/Livewire/TransactionUniverselleTest.php
git rm resources/views/transactions/all.blade.php
git commit -m "feat(lot3): remplace /transactions par TransactionUniverselle (DEP+REC)"
```

---

## Task 2 : Lot 4 — Remplacer `/comptes-bancaires/transactions`

**Files:**
- Modify: `resources/views/comptes-bancaires/transactions.blade.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`

La route `/comptes-bancaires/transactions` reste inchangée. La spec décrivait `/transactions/compte/{id}` avec un `compteId` injecté depuis l'URL, mais la route réelle dans le projet est paramétrique sans `{id}` — le compte est sélectionné via le filtre QBE intégré (dropdown dans l'en-tête de la colonne Compte). C'est le comportement intentionnel : la page "Transactions par compte" montre toutes les écritures et laisse l'utilisateur choisir le compte dans le filtre. Tous les types sont visibles (`lockedTypes` absent).

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/Livewire/TransactionUniverselleTest.php — ajouter :

it('la page /comptes-bancaires/transactions rend TransactionUniverselle sans lockedTypes', function () {
    $this->get('/comptes-bancaires/transactions')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class)
        ->assertSet('lockedTypes', null)
        ->assertSet('compteId', null);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php --filter="comptes-bancaires"
```

- [ ] **Step 3 : Réécrire `resources/views/comptes-bancaires/transactions.blade.php`**

```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-bank me-2"></i>Transactions par compte</h4>
        <livewire:transaction-universelle />
    </div>
</x-app-layout>
```

Pas de `lockedTypes` → tous les types (DEP, REC, DON, COT, VIR).
Pas de `compteId` → le filtre QBE "Compte" apparaît dans l'en-tête du tableau.

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 5 : Commit**

```bash
git add resources/views/comptes-bancaires/transactions.blade.php \
        tests/Feature/Livewire/TransactionUniverselleTest.php
git commit -m "feat(lot4): remplace /comptes-bancaires/transactions par TransactionUniverselle"
```

---

## Task 3 : Lot 5 — Remplacer `/tiers/{tiers}/transactions`

**Files:**
- Modify: `resources/views/tiers/transactions.blade.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`

La route passe déjà `$tiers` via route model binding — on le transmet en prop `tiersId`.

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/Livewire/TransactionUniverselleTest.php — ajouter :

it('la page /tiers/{id}/transactions rend TransactionUniverselle avec tiersId', function () {
    $tiers = \App\Models\Tiers::factory()->create();
    $this->get("/tiers/{$tiers->id}/transactions")
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php --filter="tiers"
```

- [ ] **Step 3 : Réécrire `resources/views/tiers/transactions.blade.php`**

```blade
<x-app-layout>
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="{{ route('tiers.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Tiers
        </a>
        <h1 class="mb-0 h4">Transactions — {{ $tiers->displayName() }}</h1>
    </div>

    <livewire:transaction-universelle
        :tiersId="$tiers->id"
        :lockedTypes="['depense', 'recette', 'don', 'cotisation']" />
</x-app-layout>
```

Note : `lockedTypes` exclut `virement` car les virements n'ont pas de tiers (le service retourne déjà 0 lignes pour eux quand `$tiersId` est non-null, mais `lockedTypes` évite d'afficher les boutons de filtre inutiles).

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 5 : Commit**

```bash
git add resources/views/tiers/transactions.blade.php \
        tests/Feature/Livewire/TransactionUniverselleTest.php
git commit -m "feat(lot5): remplace /tiers/{id}/transactions par TransactionUniverselle"
```

---

## Task 4 : Lot 6 — Remplacer `/dons`

**Files:**
- Modify: `resources/views/dons/index.blade.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/Livewire/TransactionUniverselleTest.php — ajouter :

it('la page /dons rend TransactionUniverselle avec lockedTypes don', function () {
    $this->get('/dons')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['don']])
        ->assertSet('lockedTypes', ['don']);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php --filter="dons"
```

- [ ] **Step 3 : Réécrire `resources/views/dons/index.blade.php`**

```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-heart me-2"></i>Dons</h4>
        <livewire:transaction-universelle :lockedTypes="['don']" />
    </div>
</x-app-layout>
```

- [ ] **Step 4 : Lancer les tests**

```bash
./vendor/bin/sail artisan test
```

- [ ] **Step 5 : Commit**

```bash
git add resources/views/dons/index.blade.php \
        tests/Feature/Livewire/TransactionUniverselleTest.php
git commit -m "feat(lot6): remplace /dons par TransactionUniverselle"
```

---

## Task 5 : Lot 7 — Remplacer `/cotisations`

**Files:**
- Modify: `resources/views/cotisations/index.blade.php`
- Modify: `tests/Feature/Livewire/TransactionUniverselleTest.php`

- [ ] **Step 1 : Écrire le test**

```php
// tests/Feature/Livewire/TransactionUniverselleTest.php — ajouter :

it('la page /cotisations rend TransactionUniverselle avec lockedTypes cotisation', function () {
    $this->get('/cotisations')
        ->assertStatus(200)
        ->assertSeeLivewire(TransactionUniverselle::class);

    Livewire::test(TransactionUniverselle::class, ['lockedTypes' => ['cotisation']])
        ->assertSet('lockedTypes', ['cotisation']);
});
```

- [ ] **Step 2 : Lancer le test pour vérifier qu'il échoue**

```bash
./vendor/bin/sail artisan test tests/Feature/Livewire/TransactionUniverselleTest.php --filter="cotisations"
```

- [ ] **Step 3 : Réécrire `resources/views/cotisations/index.blade.php`**

```blade
<x-app-layout>
    <div class="container-fluid py-3">
        <h4 class="mb-3"><i class="bi bi-people me-2"></i>Cotisations</h4>
        <livewire:transaction-universelle :lockedTypes="['cotisation']" />
    </div>
</x-app-layout>
```

- [ ] **Step 4 : Lancer tous les tests une dernière fois**

```bash
./vendor/bin/sail artisan test
```

Expected : tous les tests passent. Les anciens tests `DonListTest` et `CotisationListTest` testent les composants directement (pas la route), ils restent valides.

- [ ] **Step 5 : Commit final**

```bash
git add resources/views/cotisations/index.blade.php \
        tests/Feature/Livewire/TransactionUniverselleTest.php
git commit -m "feat(lot7): remplace /cotisations par TransactionUniverselle"
```
