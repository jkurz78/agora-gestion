# Batch B — Corrections & Améliorations des listes — Design

**Date :** 2026-03-12
**Branche :** staging

---

## Périmètre

Cinq évolutions indépendantes regroupées en un batch :

| # | Item |
|---|------|
| 1 | Bouton modifier les informations d'un compte bancaire |
| 2 | Rester sur l'onglet "Comptes bancaires" après une action sur un compte |
| 6 | Erreurs FK lors d'une suppression → message utilisateur au lieu du crash |
| 9 | Colonne "Référence" dans les listes dépenses et recettes |
| 10 | Filtre "Bénéficiaire" / "Payeur" dans les listes dépenses et recettes |

---

## Contexte technique

- **Paramètres / Comptes bancaires** : gestion via `CompteBancaireController` (controller classique), vue `parametres/index.blade.php` avec tabs Bootstrap 5. `update()` est déjà implémenté mais aucun bouton Modifier n'existe dans la vue.
- **Tabs Bootstrap** : navigation côté client uniquement. Après un redirect, la page charge toujours avec l'onglet "Catégories" actif par défaut.
- **DepenseList / RecetteList** : composants Livewire. Les champs `reference` (les deux) et `beneficiaire` (Depense) / `payeur` (Recette) existent déjà en base mais ne sont pas exposés dans les vues de liste.
- **Suppression FK** : aucun `try/catch` dans les `destroy()` — une violation de contrainte remonte en exception non gérée.
- **Flash messages** : composant `x-flash-message` gère déjà les cas `success` **et** `error` — aucune modification nécessaire sur ce fichier.

---

## #1 — Bouton modifier compte bancaire

### Approche : modale Bootstrap

Un seul `<div class="modal">` dans `parametres/index.blade.php`, réutilisé pour tous les comptes.

**Bouton dans le tableau :**
```html
<button type="button" class="btn btn-sm btn-outline-secondary"
        data-bs-toggle="modal" data-bs-target="#editCompteBancaireModal"
        data-id="{{ $compte->id }}"
        data-nom="{{ $compte->nom }}"
        data-iban="{{ $compte->iban }}"
        data-solde="{{ $compte->solde_initial }}"
        data-date="{{ $compte->date_solde_initial?->format('Y-m-d') }}">
    <i class="bi bi-pencil"></i>
</button>
```

**Modale :**
- Formulaire `PUT /parametres/comptes-bancaires/{id}` (champ caché `_method=PUT`)
- Champs : `nom` (requis), `iban` (optionnel), `solde_initial` (requis), `date_solde_initial` (requis)
- Pre-remplissage via un `<script>` vanilla qui écoute l'événement `show.bs.modal` et copie les `data-*` dans les champs

**Script :**
```js
document.getElementById('editCompteBancaireModal')
    .addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        this.querySelector('[name="nom"]').value         = btn.dataset.nom;
        this.querySelector('[name="iban"]').value        = btn.dataset.iban ?? '';
        this.querySelector('[name="solde_initial"]').value = btn.dataset.solde;
        this.querySelector('[name="date_solde_initial"]').value = btn.dataset.date ?? '';
        this.querySelector('form').action =
            '/parametres/comptes-bancaires/' + btn.dataset.id;
    });
```

---

## #2 — Rester sur l'onglet "Comptes bancaires" après action

**Controller :** toutes les actions de `CompteBancaireController` (store, update, destroy) redirigent avec :
```php
->with('activeTab', 'comptes')
```

**Vue `parametres/index.blade.php` :** script au bas de la page :
```blade
@if(session('activeTab') === 'comptes')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        bootstrap.Tab.getOrCreateInstance(
            document.getElementById('comptes-tab')
        ).show();
    });
</script>
@endif
```

Ce mécanisme est extensible : pour activer un autre onglet, passer la valeur correspondante à l'`id` du bouton tab (`categories`, `sous-categories`, `utilisateurs`, `operations`).

---

## #6 — Erreurs FK → message utilisateur

**Composant `x-flash-message` :** déjà opérationnel pour `error` — aucune modification nécessaire.

**Controllers concernés :** `CompteBancaireController`, `CategorieController`, `SousCategorieController`.

Chaque controller garde son propre nom de paramètre typé (route-model binding) — ne pas utiliser le placeholder générique `Model $model`.

Pattern à appliquer dans chaque `destroy()` (en conservant la signature existante) :

`CompteBancaireController` (redirige vers `parametres.index` + `activeTab` pour le mécanisme #2) :
```php
public function destroy(CompteBancaire $comptesBancaire): RedirectResponse
{
    try {
        $comptesBancaire->delete();
        return redirect()->route('parametres.index')
            ->with('success', 'Compte bancaire supprimé avec succès.')
            ->with('activeTab', 'comptes');
    } catch (\Illuminate\Database\QueryException $e) {
        if ($e->getCode() === '23000') {
            return redirect()->route('parametres.index')
                ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.')
                ->with('activeTab', 'comptes');
        }
        throw $e;
    }
}
```

`CategorieController` (redirige vers `parametres.index`) :
```php
public function destroy(Categorie $category): RedirectResponse
{
    try {
        $category->delete();
        return redirect()->route('parametres.index')
            ->with('success', 'Catégorie supprimée avec succès.');
    } catch (\Illuminate\Database\QueryException $e) {
        if ($e->getCode() === '23000') {
            return redirect()->route('parametres.index')
                ->with('error', 'Suppression impossible : cet élément est utilisé dans les données de l\'application.');
        }
        throw $e;
    }
}
```

`SousCategorieController` : même pattern avec `SousCategorie $sousCategory` (nom anglais, sans accent, comme dans le controller existant).

**Note :** Les codes d'erreur SQL autres que `23000` sont re-thrown pour ne pas masquer des erreurs inattendues.

---

## #9 — Colonne "Référence" dans les listes

**Positionnement :** après "Libellé", avant "Montant".

**`depense-list.blade.php` :**
- `<th>Référence</th>` dans le `<thead>`
- `<td>{{ $depense->reference ?? '-' }}</td>` dans le `<tbody>`

**`recette-list.blade.php` :** idem avec `$recette->reference`.

Pas de modification des composants PHP — le champ `reference` est déjà dans `$fillable` et chargé par la query existante.

---

## #10 — Filtre "Bénéficiaire" / "Payeur" dans les listes

### DepenseList

**`app/Livewire/DepenseList.php` :**
```php
public ?string $beneficiaire = null;

public function updatedBeneficiaire(): void
{
    $this->resetPage();
}
```

Clause dans la query :
```php
->when($this->beneficiaire, fn ($q) => $q->where('beneficiaire', 'like', '%'.$this->beneficiaire.'%'))
```

**Vue `depense-list.blade.php` :** champ texte dans la zone des filtres :
```html
<input type="text" wire:model.live.debounce.300ms="beneficiaire"
       class="form-control form-control-sm" placeholder="Bénéficiaire...">
```

### RecetteList

**`app/Livewire/RecetteList.php` :**
```php
public ?string $payeur = null;

public function updatedPayeur(): void
{
    $this->resetPage();
}
```

Clause dans la query :
```php
->when($this->payeur, fn ($q) => $q->where('payeur', 'like', '%'.$this->payeur.'%'))
```

**Vue `recette-list.blade.php` :** champ texte dans la zone des filtres :
```html
<input type="text" wire:model.live.debounce.300ms="payeur"
       class="form-control form-control-sm" placeholder="Payeur...">
```

---

## Fichiers à modifier / créer

| Action | Fichier |
|--------|---------|
| Modifier | `resources/views/parametres/index.blade.php` |
| Modifier | `app/Http/Controllers/CompteBancaireController.php` |
| Modifier | `app/Http/Controllers/CategorieController.php` |
| Modifier | `app/Http/Controllers/SousCategorieController.php` |
| Modifier | `app/Livewire/DepenseList.php` |
| Modifier | `resources/views/livewire/depense-list.blade.php` |
| Modifier | `app/Livewire/RecetteList.php` |
| Modifier | `resources/views/livewire/recette-list.blade.php` |

## Tests

| Item | Type | Stratégie |
|------|------|-----------|
| #1 | Feature | `PUT /parametres/comptes-bancaires/{id}` met à jour le compte |
| #2 | Feature | Après store/update/destroy, session contient `activeTab=comptes` |
| #6 | Feature | `DELETE` sur un compte/catégorie utilisé → redirect avec flash `error` |
| #9 | Livewire | `DepenseList`/`RecetteList` affichent la colonne Référence |
| #10 | Livewire | Filtre bénéficiaire/payeur restreint les résultats |
