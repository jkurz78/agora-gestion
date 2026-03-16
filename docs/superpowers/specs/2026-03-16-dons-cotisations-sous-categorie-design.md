# Spec : Sous-catégorie sur Dons et Cotisations

**Date :** 2026-03-16
**Chantiers couverts :** #2 (cotisations) + #4 (dons)
**Statut :** Approuvé

---

## Contexte

Les dons et cotisations n'ont actuellement pas de `sous_categorie_id`. Ils sont donc absents des rapports (compte de résultat). Cette spec ajoute la notion de **nature comptable** sur ces deux entités, via un flag sur la table `sous_categories`, sans créer d'écran de paramétrage supplémentaire.

---

## Section 1 — Modèle de données

### Migrations (une seule migration)

**Table `sous_categories`** : ajout de deux colonnes booléennes :
- `pour_dons` boolean, NOT NULL, default false
- `pour_cotisations` boolean, NOT NULL, default false

**Table `dons`** : ajout de `sous_categorie_id` (FK NOT NULL vers `sous_categories.id`).
La colonne est ajoutée directement en NOT NULL — aucun enregistrement existant à migrer (supprimé manuellement avant la migration).

**Table `cotisations`** : ajout de `sous_categorie_id` (FK NOT NULL vers `sous_categories.id`). Même hypothèse.

### Seeder `CategoriesSeeder`

Flags pré-cochés sur les sous-catégories standard :
- 751 Cotisations → `pour_cotisations = true`
- 754 Dons manuels → `pour_dons = true`
- 756 Mécénat → `pour_dons = true`
- 771 Abandon de créance → `pour_dons = true`

---

## Section 2 — Modèles & Services

### Modèles

**`SousCategorie`** :
- Ajout de `pour_dons` et `pour_cotisations` dans `$fillable` et `casts()` (boolean)

**`Don`** :
- Ajout de `sous_categorie_id` dans `$fillable` et `casts()` (integer)
- Ajout de la relation `sousCategorie(): BelongsTo`

**`Cotisation`** :
- Mêmes ajouts que Don

### Services

**`DonService`** : `create()` et `update()` acceptent et valident `sous_categorie_id` (required, exists:sous_categories,id).

**`CotisationService`** : idem.

### Écran sous-catégories

Ajout de deux colonnes dans la liste des sous-catégories : "Dons" et "Cotisations" (indicateur visuel ou toggle) permettant à l'admin de gérer les flags `pour_dons` et `pour_cotisations`.

---

## Section 3 — Formulaires

### DonForm

- Nouveau sélecteur **"Nature du don"** (required)
- Options : `SousCategorie::where('pour_dons', true)->orderBy('nom')->get()`
- Valeur par défaut : `sous_categorie_id` du dernier don saisi par l'utilisateur courant
  ```php
  Don::where('saisi_par', auth()->id())->latest()->value('sous_categorie_id')
  ```

### CotisationForm

- Nouveau sélecteur **"Poste comptable"** (required)
- Options : `SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get()`
- Même logique de pré-remplissage (dernière cotisation de l'utilisateur)

---

## Section 4 — Listes

### DonList

- Nouvelle colonne **"Nature du don"** affichant `sousCategorie->nom`
- Filtre par nature : select avec les sous-catégories `pour_dons = true`

### CotisationList

- Nouvelle colonne **"Poste comptable"** affichant `sousCategorie->nom`
- Filtre par poste comptable (par cohérence, même si une seule valeur en pratique)

---

## Section 5 — Rapports

### `RapportService::compteDeResultat()`

Les **produits** incluent désormais trois sources :
1. `recette_lignes` (existant)
2. `dons` → jointure sur `sous_categories` via `sous_categorie_id`, filtre par exercice (date)
3. `cotisations` → jointure sur `sous_categories` via `sous_categorie_id`, filtre par exercice

Les montants des trois sources sont agrégés par `code_cerfa` avant présentation.

Le filtre par `$operationIds` s'applique aux **dons** (qui ont un champ `operation_id`), mais **pas aux cotisations** (pas de lien opération).

---

## Fichiers impactés

| Fichier | Nature |
|---|---|
| `database/migrations/YYYY_…_add_sous_categorie_to_dons_cotisations.php` | Nouveau |
| `database/seeders/CategoriesSeeder.php` | Modification |
| `app/Models/SousCategorie.php` | Modification |
| `app/Models/Don.php` | Modification |
| `app/Models/Cotisation.php` | Modification |
| `app/Services/DonService.php` | Modification |
| `app/Services/CotisationService.php` | Modification |
| `app/Livewire/DonForm.php` | Modification |
| `app/Livewire/DonList.php` | Modification |
| `app/Livewire/CotisationForm.php` | Modification |
| `app/Livewire/CotisationList.php` | Modification |
| `app/Services/RapportService.php` | Modification |
| `resources/views/livewire/don-form.blade.php` | Modification |
| `resources/views/livewire/don-list.blade.php` | Modification |
| `resources/views/livewire/cotisation-form.blade.php` | Modification |
| `resources/views/livewire/cotisation-list.blade.php` | Modification |
| Écran sous-catégories (Livewire + vue) | Modification |
