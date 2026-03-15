# Tiers — Transactions et Refonte Membres

## Objectif

Deux améliorations liées à l'univers tiers/membres :

1. **Transactions d'un tiers** — page dédiée listant toutes les transactions (dépenses, recettes, dons, cotisations) pour un tiers donné, avec tri et filtres.
2. **Refonte Membres** — remplacer l'écran membres basé sur `statut_membre` par une vue Livewire dérivant le statut des cotisations enregistrées.

---

## Feature 1 — Transactions d'un tiers

### Route et accès

- Route : `GET /tiers/{id}/transactions` avec Route Model Binding → contrôleur ou closure qui charge `Tiers::findOrFail($id)` (404 si inexistant) et retourne la vue `tiers/transactions.blade.php`
- Vue layout : `resources/views/tiers/transactions.blade.php` contenant `<livewire:tiers-transactions :tiersId="$tiers->id" />`
- Bouton `bi-clock-history` dans la colonne actions de `tiers-list.blade.php` (entre Modifier et Supprimer), lien vers la route
- En-tête de page : « Transactions — [Nom du tiers] » avec lien retour vers `/tiers`

### Composant Livewire `TiersTransactions`

**Props :** `public int $tiersId`

**Filtres (propriétés Livewire) :**
- `string $typeFilter = ''` — vide = tous, sinon : `depense` | `recette` | `don` | `cotisation`
- `string $dateDebut = ''`
- `string $dateFin = ''`
- `string $search = ''` — recherche texte sur libellé (debounce 300ms)

**Tri :**
- `string $sortBy = 'date'` — date | type | montant
- `string $sortDir = 'desc'` — asc | desc
- Méthode `sort(string $col)` : si `$col === $this->sortBy`, bascule `sortDir` ; sinon, change `sortBy` et remet `sortDir = 'asc'`

**Colonne Libellé par type :**

| Type | Valeur affichée | Champ utilisé pour la recherche |
|------|----------------|----------------------------------|
| depense | `depenses.libelle` | `depenses.libelle` |
| recette | `recettes.libelle` | `recettes.libelle` |
| don | `dons.objet` | `dons.objet` |
| cotisation | `'Cotisation ' || exercice` | `cotisations.exercice` (cast en string) |

**Query — UNION en sous-select, même pattern que `TransactionCompteService` :**

```php
$depenses = DB::table('depenses')
    ->leftJoin('comptes_bancaires', ...)
    ->selectRaw("'depense' as source_type, date, libelle, comptes_bancaires.nom as compte, montant_total as montant")
    ->where('tiers_id', $this->tiersId)
    ->whereNull('depenses.deleted_at');

// idem recettes, dons (objet as libelle), cotisations (CONCAT('Cotisation ', exercice) as libelle)

$union = $depenses
    ->unionAll($recettes)
    ->unionAll($dons)
    ->unionAll($cotisations);

$query = DB::table(DB::raw("({$union->toSql()}) as t"))
    ->mergeBindings($union);

// Appliquer filtres, tri, pagination sur $query
$results = $query->paginate(50);
```

Le filtre `$search` s'applique sur la colonne `libelle` de la vue externe : `$query->where('libelle', 'like', "%{$this->search}%")`.

Le filtre `$typeFilter` : `$query->where('source_type', $this->typeFilter)` si non vide.

Les filtres date : `$query->where('date', '>=', $this->dateDebut)` / `<=` si renseignés.

Le tri : `$query->orderBy($this->sortBy, $this->sortDir)`. Seuls `date`, `type` (alias `source_type`), `montant` sont autorisés (liste blanche).

Toutes les sous-queries incluent `WHERE deleted_at IS NULL` explicitement.

**Compte null :** afficher `—` si `compte` est null.

**Colonnes du tableau :**

| Date | Type | Libellé | Compte | Montant |
|------|------|---------|--------|---------|
| dd/mm/yyyy | badge coloré | texte | nom ou `—` | coloré (rouge : dépense, cotisation ; vert : recette, don) |

**Badges type :** `bg-danger` dépense, `bg-success` recette, `bg-info` don, `bg-secondary` cotisation.

**En-têtes cliquables pour tri :** Date, Type, Montant — indicateur ▲▼ sur la colonne active.

**Barre de filtres :**
- Select Type : Tous / Dépense / Recette / Don / Cotisation
- Inputs date de/à
- Input texte recherche libellé

**Pas de filtre par exercice** — l'historique complet est affiché ; les filtres date permettent de restreindre la période.

**Pagination Bootstrap** (50 lignes/page).

### Fichiers à créer

- `app/Livewire/TiersTransactions.php`
- `resources/views/livewire/tiers-transactions.blade.php`
- `resources/views/tiers/transactions.blade.php`

### Fichiers à modifier

- `routes/web.php` — ajouter `GET /tiers/{tiers}/transactions`
- `resources/views/livewire/tiers-list.blade.php` — ajouter bouton `bi-clock-history`

---

## Feature 2 — Refonte Membres

### Suppression du legacy

**Migration :**
- `dropColumn` sur `tiers` : `statut_membre`, `date_adhesion`, `notes_membre`
- Pas de migration de données (confirmé : aucune donnée en prod)

**Fichiers à supprimer :**
- `app/Http/Controllers/MembreController.php`
- `app/Http/Requests/StoreMembreRequest.php`
- `app/Http/Requests/UpdateMembreRequest.php`
- `resources/views/membres/create.blade.php`
- `resources/views/membres/edit.blade.php`
- `resources/views/membres/show.blade.php`
- `resources/views/membres/index.blade.php` (ancienne version)

**Modèle `Tiers` :**
- Retirer `statut_membre`, `date_adhesion`, `notes_membre` du `$fillable` et `casts()`
- Supprimer `scopeMembres()`

**Routes :**
- Supprimer `Route::resource('membres', MembreController::class)`
- Ajouter `Route::view('/membres', 'membres.index')->name('membres.index')`

### Nouvelle page `/membres`

**Vue `resources/views/membres/index.blade.php` :**
```blade
<x-app-layout>
    <livewire:cotisation-form />
    <livewire:membre-list />
</x-app-layout>
```

`CotisationForm` est rendu en haut de page avec son propre bouton "Nouvelle cotisation". `MembreList` n'ajoute pas de second bouton global (redondant).

### Composant Livewire `MembreList`

**Définition du statut membre :**

Exercice courant = méthode existante sur `ExerciceService` (vérifier le nom exact dans le code).

- **À jour** : `whereHas('cotisations', fn($q) => $q->forExercice($exerciceCourant))`
- **En retard** : `whereHas('cotisations', fn($q) => $q->forExercice($exerciceCourant - 1))->whereDoesntHave('cotisations', fn($q) => $q->forExercice($exerciceCourant))` — exercice précédent = N-1 uniquement
- **Tous** : `whereHas('cotisations')` — tout tiers ayant au moins une cotisation, quel que soit l'exercice

**Propriétés :**
- `string $filtre = 'a_jour'`
- `string $search = ''`

**Données par ligne :** pour chaque tiers, charger sa cotisation la plus récente (`cotisations()->latest('date_paiement')->first()`) pour afficher date, montant, mode, compte, pointé.

**Colonnes :**

| Nom | Date dernière cotisation | Montant | Mode | Compte | Pointé | Actions |
|-----|--------------------------|---------|------|--------|--------|---------|
| 🏢/👤 Nom | dd/mm/yyyy (exercice N) | XX,XX € | badge | nom | ✓/— | bouton cotisation |

**Bouton "Nouvelle cotisation" par ligne :**
- `wire:click="$dispatch('open-cotisation-for-tiers', { tiersId: {{ $tiers->id }} })"`
- `CotisationForm` écoute et s'ouvre avec ce tiers pré-sélectionné

### Adaptation de `CotisationForm`

Ajouter méthode :

```php
#[On('open-cotisation-for-tiers')]
public function openForTiers(?int $tiersId = null): void
{
    $this->resetForm();      // remet les champs à zéro, date du jour, etc.
    $this->showForm = true;
    if ($tiersId !== null) {
        $this->tiers_id = $tiersId;
        // Livewire pousse tiers_id vers TiersAutocomplete via #[Modelable]
        // TiersAutocomplete::updatedTiersId() recharge selectedLabel/selectedType
    }
}
```

### Adaptation de `TiersAutocomplete`

Ajouter le hook `updatedTiersId()` pour que l'autocomplete réagisse quand le parent change `tiersId` programmatiquement :

```php
public function updatedTiersId(mixed $value): void
{
    $id = ($value !== '' && $value !== null) ? (int) $value : null;
    $this->tiersId = $id;
    if ($id !== null) {
        $tiers = Tiers::find($id);
        $this->selectedLabel = $tiers?->displayName();
        $this->selectedType  = $tiers?->type;
    } else {
        $this->selectedLabel = null;
        $this->selectedType  = null;
    }
}
```

Ce hook corrige aussi le cas général où un parent change `tiersId` sans passer par l'interaction utilisateur.

### Fichiers à créer

- `app/Livewire/MembreList.php`
- `resources/views/livewire/membre-list.blade.php`
- `resources/views/membres/index.blade.php` (nouvelle version)
- `database/migrations/YYYY_MM_DD_drop_membre_columns_from_tiers.php`

### Fichiers à modifier

- `app/Models/Tiers.php`
- `app/Livewire/CotisationForm.php`
- `app/Livewire/TiersAutocomplete.php`
- `routes/web.php`

### Fichiers à supprimer

- `app/Http/Controllers/MembreController.php`
- `app/Http/Requests/StoreMembreRequest.php`
- `app/Http/Requests/UpdateMembreRequest.php`
- `resources/views/membres/create.blade.php`
- `resources/views/membres/edit.blade.php`
- `resources/views/membres/show.blade.php`
- `resources/views/membres/index.blade.php` (ancienne version)

---

## Tests

### TiersTransactions

- Rendu de la page avec tiers valide (200, titre correct)
- Rendu avec tiers inexistant → 404
- Sans transactions : message "Aucune transaction"
- Filtre `typeFilter = 'don'` → seules les lignes `source_type = don` apparaissent
- Filtre `search = 'cotisation'` → filtre sur libellé
- Tri par montant desc → ligne au montant le plus élevé en premier
- Filtre date : seules les transactions dans la plage apparaissent

### MembreList

- Filtre `a_jour` retourne uniquement les tiers avec cotisation exercice courant
- Filtre `en_retard` retourne les tiers avec cotisation N-1 et aucune sur N
- Filtre `tous` retourne l'union des deux
- Dispatch `open-cotisation-for-tiers` avec tiersId → CotisationForm s'ouvre, tiers pré-sélectionné visible

### TiersAutocomplete

- Quand le parent modifie `tiersId` programmatiquement, `selectedLabel` et `selectedType` se mettent à jour

### Migration

- Après `migrate:fresh`, la table `tiers` ne contient plus les colonnes `statut_membre`, `date_adhesion`, `notes_membre`
