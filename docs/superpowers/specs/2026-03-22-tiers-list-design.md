# Tiers — Écran liste amélioré

**Date :** 2026-03-22
**Contexte :** Suite de `2026-03-21-tiers-restructuration`. Le modèle `Tiers` est enrichi (ville, entreprise, helloasso_id…). L'écran liste doit en tirer parti : meilleure lisibilité, recherche élargie, tri, indicateur HelloAsso.

---

## Prérequis

Cette spec suppose que la branche `feat/tiers-restructuration` (spec `2026-03-21-tiers-restructuration-design.md`) est **mergée avant implémentation**. Elle introduit les colonnes `entreprise`, `ville`, `helloasso_id`, `adresse_ligne1`, `code_postal`, `pays`, `date_naissance` sur la table `tiers`, ainsi que la méthode `displayName()` mise à jour (`entreprise ?? nom` pour les entreprises). Sans ce prérequis, les requêtes SQL de la présente spec échoueraient.

Le badge **HA** dans la colonne Nom est déjà implémenté dans la branche `feat/tiers-restructuration` — il n'est pas à refaire.

---

## 1. Colonnes du tableau

### Avant → Après

| Avant | Après | Changement |
|---|---|---|
| Nom | **Nom** (icône type + displayName + sous-ligne contact + badge HA) | Enrichi |
| Type (badge texte) | *(supprimé)* | Remplacé par icône dans Nom. **Note :** cette colonne existe dans `feat/tiers-restructuration` — sa suppression ici est intentionnelle. |
| Email | **Email** | Inchangé |
| Téléphone | **Téléphone** | Inchangé |
| Dép. ✓ | **Dép.** ✓ | Inchangé |
| Rec. ✓ | **Rec.** ✓ | Inchangé |
| *(absent)* | **Ville** | Nouveau |
| Actions | **Actions** | Inchangé |

Total : 7 colonnes → 7 colonnes (Type remplacé par Ville).

### Colonne Nom — détail

```
👤 Dupont Jean                          ← particulier, pas de sous-ligne
🏢 ACME Corp          [HA]             ← entreprise avec helloasso_id
   Jean Dupont                          ← sous-ligne contact si nom renseigné
🏢 Fournisseur Bio                      ← entreprise sans contact ni HA
```

- Icône `👤` / `🏢` avant le `displayName()` — mêmes icônes que `TiersAutocomplete`
- Badge **HA** : **déjà présent dans la branche prérequis — ne pas modifier**. Documenté ici pour contexte uniquement : badge violet (`#722281`), tooltip avec l'identifiant, visible si `helloasso_id` non null.
- Sous-ligne grisée (`text-muted small`) : `trim(prenom . ' ' . nom)` du contact si au moins l'un des deux est renseigné, uniquement pour `type = entreprise`

### Colonne Ville

- Affiche `$tiers->ville ?? '—'`
- Triable

---

## 2. Filtres et recherche

### Barre de filtres — layout

```
[ Recherche texte (col-md-5) ] [ Usage (col-md-3) ] [ ☐ HelloAsso uniquement (col-md-auto) ]
```

### Recherche textuelle élargie

Actuellement : `nom`, `prenom`.
Après : `nom`, `prenom`, `entreprise`, `ville`, `email`.

```php
$query->where(function ($q): void {
    $q->where('nom',         'like', "%{$this->search}%")
      ->orWhere('prenom',    'like', "%{$this->search}%")
      ->orWhere('entreprise','like', "%{$this->search}%")
      ->orWhere('ville',     'like', "%{$this->search}%")
      ->orWhere('email',     'like', "%{$this->search}%");
});
```

Cela permet de trouver "ACME" même si la raison sociale est dans `entreprise`, et de filtrer par ville.

### Filtre HelloAsso

Checkbox `wire:model.live="filtreHelloasso"` : si cochée → `->whereNotNull('helloasso_id')`.

**Justification :** après l'import HelloAsso, l'utilisateur devra comparer/dédupliquer les tiers importés vs les tiers locaux. Ce filtre est le premier outil de ce workflow.

### Filtre Usage (inchangé)

`Tous` / `Utilisables en dépenses` / `Utilisables en recettes` — conservé tel quel.

### Pas de QBE

Le volume des tiers ne justifie pas un QBE par colonne. La recherche textuelle élargie + les deux filtres couvrent les besoins. À reconsidérer post-import HelloAsso si le volume dépasse quelques centaines.

---

## 3. Tri des colonnes

### Colonnes triables

| Colonne | Champ SQL | Défaut |
|---|---|---|
| Nom | `COALESCE(entreprise, nom)` | ✓ ASC |
| Ville | `ville` | — |
| Email | `email` | — |

Les colonnes Dép., Rec., Téléphone, Actions ne sont pas triables.

### Tri sur Nom — cas particulier

`displayName()` est une méthode PHP, non triable en SQL directement. Solution : `COALESCE(entreprise, nom)`.

- Pour une entreprise : `entreprise` est renseigné → tri sur la raison sociale ✓
- Pour un particulier : `entreprise` est null → tri sur `nom` (nom de famille)

**Note délibérée :** pour un particulier, `displayName()` retourne `"Prénom Nom"` mais le tri se fait sur `nom` seul. Cela signifie que "Jean Dupont" est classé à "D", pas à "J". Ce comportement est intentionnel — trier par nom de famille est plus utile en pratique que trier par `CONCAT(prenom, ' ', nom)`, et c'est cohérent avec l'ordre alphabétique attendu dans un annuaire.

```php
if ($this->sortBy === 'nom') {
    $query->orderByRaw('COALESCE(entreprise, nom) ' . $this->sortDir);
} else {
    $query->orderBy($this->sortBy, $this->sortDir);
}
```

### Pattern PHP (conforme aux autres composants)

```php
public string $sortBy  = 'nom';
public string $sortDir = 'asc';
public bool   $filtreHelloasso = false;

public function sort(string $col): void
{
    $allowed = ['nom', 'ville', 'email'];
    if (! in_array($col, $allowed, true)) {
        return;
    }
    if ($this->sortBy === $col) {
        $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy  = $col;
        $this->sortDir = 'asc';
    }
    $this->resetPage();
}

public function updatedFiltreHelloasso(): void
{
    $this->resetPage();
}
```

### Pattern Blade — en-têtes triables

Le `<thead>` doit utiliser le style sombre standard du projet (le fichier actuel utilise `table-light` — à corriger) :

```blade
<thead class="table-dark" style="--bs-table-bg:#3d5473;--bs-table-border-color:#4d6880">
```

En-tête triable (exemple sur Nom) :

```blade
<th>
    <a href="#" wire:click.prevent="sort('nom')" class="text-white text-decoration-none">
        Nom
        @if($sortBy === 'nom')
            <i class="bi bi-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }}"></i>
        @endif
    </a>
</th>
```

---

## 4. Fichiers modifiés

| Fichier | Nature |
|---|---|
| `app/Livewire/TiersList.php` | Ajout `sortBy`, `sortDir`, `filtreHelloasso`, méthodes `sort()` et `updatedFiltreHelloasso()`, mise à jour `render()` |
| `resources/views/livewire/tiers-list.blade.php` | Nouveau layout colonnes, icônes, sous-ligne contact, tri, filtre HA |

Aucun nouveau fichier. Aucune modification du modèle, du service, des routes, ni des tests existants de suppression/édition.

---

## 5. Tests

### TiersListTest — cas à ajouter

| Test | Description |
|---|---|
| Recherche sur `entreprise` | Un tiers entreprise "ACME Corp" remonte si on cherche "ACME" |
| Recherche sur `ville` | Un tiers avec `ville = "Lyon"` remonte si on cherche "Lyon" |
| Filtre HelloAsso actif | Seuls les tiers avec `helloasso_id` non null apparaissent |
| Filtre HelloAsso inactif | Tous les tiers apparaissent (comportement par défaut) |
| Tri par nom ASC | Ordre COALESCE(entreprise, nom) croissant |
| Tri par nom DESC | Ordre COALESCE(entreprise, nom) décroissant |
| Tri par ville | Ordre alphabétique sur `ville` |
| Sous-ligne contact visible | Entreprise avec `nom` contact renseigné : sous-ligne présente |
| Sous-ligne contact absente | Entreprise sans `nom` ni `prenom` : pas de sous-ligne |
| Icône particulier | Tiers particulier affiche 👤 |
| Icône entreprise | Tiers entreprise affiche 🏢 |
| Entreprise sans raison sociale | Tiers `type=entreprise` avec `entreprise=null` : `displayName()` affiche `nom`, tri COALESCE se rabat sur `nom` |

---

## 6. Hors scope

- Modification du modèle `Tiers` (fait dans la restructuration prérequise)
- Import / synchronisation HelloAsso (lot suivant)
- Fusion/déduplication de tiers (lot suivant)
- Colonne `date_naissance` en liste (peu pertinente, reste dans le formulaire)
- QBE par colonne (non justifié au volume actuel)
